<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Box;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ShipmentPackageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_adding_a_package_recalculates_the_shipment_summary_and_normalizes_units(): void
    {
        $company = $this->createCompany('SPA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/packages", [
                'weight_kg' => 22,
                'weight_unit' => 'lb',
                'length' => 20,
                'width' => 20,
                'height' => 20,
                'dimension_unit' => 'in',
            ])
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $shipment->refresh();
            self::assertSame(2, $shipment->package_count);

            $second = $shipment->packages()->where('package_number', 2)->firstOrFail();
            self::assertEqualsWithDelta(9.979, (float) $second->weight_kg, 0.01);
            self::assertEqualsWithDelta(50.8, (float) $second->length_cm, 0.01);
            self::assertSame("{$shipment->tracking_number}-02", $second->barcode);

            self::assertEqualsWithDelta((float) $shipment->packages->sum('weight_kg'), (float) $shipment->declared_weight_kg, 0.01);
            self::assertEqualsWithDelta((float) $shipment->packages->sum('volumetric_weight_kg'), (float) $shipment->volumetric_weight_kg, 0.01);
        } finally {
            $context->forget();
        }
    }

    public function test_a_package_can_be_added_without_a_weight(): void
    {
        $company = $this->createCompany('SPW');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/packages", ['length' => 40, 'width' => 30, 'height' => 20])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment): void {
            $shipment->refresh();
            $second = $shipment->packages()->where('package_number', 2)->firstOrFail();

            // Not entered is stored as 0 (a real weight can never be), it does not
            // count toward the shipment's actual weight, and the box still
            // contributes its volumetric weight.
            self::assertSame('0.000', $second->weight_kg);
            self::assertGreaterThan(0, (float) $second->volumetric_weight_kg);
            self::assertEqualsWithDelta(5.0, (float) $shipment->declared_weight_kg, 0.001);
            self::assertSame(2, $shipment->package_count);
        });
    }

    public function test_a_shipment_can_be_booked_without_any_package_weight(): void
    {
        $company = $this->createCompany('SPX');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/shipments', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['length' => 50, 'width' => 40, 'height' => 30]],
        ])->assertSessionHasNoErrors();

        $this->withTenant($company, function (): void {
            $shipment = Shipment::query()->latest('id')->firstOrFail();

            self::assertSame(1, $shipment->package_count);
            self::assertEqualsWithDelta(0.0, (float) $shipment->declared_weight_kg, 0.001);
            self::assertGreaterThan(0, (float) $shipment->chargeable_weight_kg);
        });
    }

    public function test_a_weight_that_is_entered_must_still_be_a_positive_number(): void
    {
        $company = $this->createCompany('SPY');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/packages", ['weight_kg' => -3])
            ->assertSessionHasErrors('weight_kg');
    }

    public function test_a_shipment_must_keep_at_least_one_package(): void
    {
        $company = $this->createCompany('SPB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $packageId = $shipment->packages()->firstOrFail()->getKey();
        $context->forget();

        $this->actingAs($actor)
            ->delete("/shipments/{$shipment->getKey()}/packages/{$packageId}")
            ->assertSessionHasErrors('package');
    }

    public function test_a_package_cannot_be_modified_on_a_shipment_from_another_company(): void
    {
        $companyA = $this->createCompany('SPC');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['shipments.view', 'shipments.manage']);

        $companyB = $this->createCompany('SPD');
        $branchB = $this->createBranch($companyB, 'MNL');
        $actorB = $this->createUser($companyB, $branchB);
        $this->grantPermissions($actorB, ['shipments.view', 'shipments.manage']);
        $shipmentB = $this->shipmentWithOnePackage($companyB, $branchB, $actorB);

        $this->actingAs($actorA)
            ->post("/shipments/{$shipmentB->getKey()}/packages", ['weight_kg' => 1])
            ->assertNotFound();
    }

    public function test_selecting_a_box_size_fills_in_its_preset_dimensions(): void
    {
        $company = $this->createCompany('SPE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);
        $boxSize = $this->withTenant($company, function () {
            $box = Box::query()->create(['name' => 'Standard Box']);

            return $box->sizes()->create(['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50]);
        });

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/packages", [
                'weight_kg' => 15,
                'box_size_id' => $boxSize->getKey(),
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment, $boxSize): void {
            $second = $shipment->packages()->where('package_number', 2)->firstOrFail();
            self::assertSame($boxSize->getKey(), $second->box_size_id);
            self::assertSame('80.00', $second->length_cm);
            self::assertSame('60.00', $second->width_cm);
            self::assertSame('50.00', $second->height_cm);
        });
    }

    public function test_an_explicit_dimension_overrides_the_box_sizes_preset(): void
    {
        $company = $this->createCompany('SPF');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->shipmentWithOnePackage($company, $branch, $actor);
        $boxSize = $this->withTenant($company, function () {
            $box = Box::query()->create(['name' => 'Standard Box']);

            return $box->sizes()->create(['name' => 'Small', 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10]);
        });

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/packages", [
                'weight_kg' => 3,
                'box_size_id' => $boxSize->getKey(),
                'length' => 99,
                'width' => 15,
                'height' => 10,
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment): void {
            $second = $shipment->packages()->where('package_number', 2)->firstOrFail();
            self::assertSame('99.00', $second->length_cm);
        });
    }

    public function test_a_box_size_from_another_company_is_rejected(): void
    {
        $companyA = $this->createCompany('SPG');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['shipments.view', 'shipments.manage']);
        $shipmentA = $this->shipmentWithOnePackage($companyA, $branchA, $actorA);

        $companyB = $this->createCompany('SPH');
        $boxSizeB = $this->withTenant($companyB, function () {
            $box = Box::query()->create(['name' => 'Standard Box']);

            return $box->sizes()->create(['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50]);
        });

        $this->actingAs($actorA)
            ->post("/shipments/{$shipmentA->getKey()}/packages", [
                'weight_kg' => 5,
                'box_size_id' => $boxSizeB->getKey(),
            ])
            ->assertSessionHasErrors('box_size_id');
    }

    private function withTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    private function shipmentWithOnePackage(Company $company, Branch $branch, User $actor): Shipment
    {
        $this->actingAs($actor)->post('/shipments', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5, 'length' => 50, 'width' => 40, 'height' => 30]],
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return Shipment::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
