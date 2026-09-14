<?php

declare(strict_types=1);

namespace Tests\Feature\Freight;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoadUnit;
use App\Models\Manifest;
use App\Models\Master;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class LoadUnitAndManifestTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_loading_a_package_reconciles_load_unit_and_master_aggregates(): void
    {
        $company = $this->createCompany('LMA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage', 'containers.view', 'containers.manage', 'shipments.view', 'shipments.manage']);

        $master = $this->createMaster($company, $branch, $actor);
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", [
            'type' => 'pallet', 'unit_number' => 'ULD-0001',
        ]);
        $unit = $this->unitFor($company, $master->getKey());

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/load-units/{$unit->getKey()}/packages", ['package_id' => $package->getKey()])
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $unit->refresh();
            $master->refresh();
            self::assertSame(1, $unit->package_count);
            self::assertEqualsWithDelta(5.0, (float) $unit->weight_kg, 0.001);
            self::assertSame(1, $master->package_count);
            self::assertEqualsWithDelta(5.0, (float) $master->weight_kg, 0.001);
        } finally {
            $context->forget();
        }
    }

    public function test_a_load_unit_cannot_be_marked_loaded_while_empty(): void
    {
        $company = $this->createCompany('LMB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage', 'containers.view', 'containers.manage']);
        $master = $this->createMaster($company, $branch, $actor);

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'pallet', 'unit_number' => 'ULD-0002']);
        $unit = $this->unitFor($company, $master->getKey());

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/load-units/{$unit->getKey()}/transitions", ['status' => 'loaded'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_package_cannot_be_loaded_twice_without_unloading_first(): void
    {
        $company = $this->createCompany('LMC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage', 'containers.view', 'containers.manage', 'shipments.view', 'shipments.manage']);
        $master = $this->createMaster($company, $branch, $actor);
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'pallet', 'unit_number' => 'ULD-0003']);
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'pallet', 'unit_number' => 'ULD-0004']);
        $units = $this->unitsFor($company, $master->getKey());

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units/{$units[0]->getKey()}/packages", ['package_id' => $package->getKey()]);

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/load-units/{$units[1]->getKey()}/packages", ['package_id' => $package->getKey()])
            ->assertSessionHasErrors('package');
    }

    public function test_generating_a_manifest_requires_the_master_to_be_closed_and_snapshots_the_load(): void
    {
        $company = $this->createCompany('LMD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage', 'containers.view', 'containers.manage', 'shipments.view', 'shipments.manage', 'manifests.view', 'manifests.manage']);
        $master = $this->createMaster($company, $branch, $actor);
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/manifests")
            ->assertSessionHasErrors('status');

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'pallet', 'unit_number' => 'ULD-0005']);
        $unit = $this->unitFor($company, $master->getKey());
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units/{$unit->getKey()}/packages", ['package_id' => $package->getKey()]);
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'closed']);

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/manifests")
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $manifest = Manifest::query()->where('master_id', $master->getKey())->firstOrFail();
            self::assertSame(1, $manifest->version);
            self::assertSame(1, $manifest->snapshot['package_count']);
            self::assertCount(1, $manifest->snapshot['load_units']);
            self::assertSame($package->barcode, $manifest->snapshot['load_units'][0]['packages'][0]['barcode']);

            $this->expectException(\LogicException::class);
            $manifest->update(['manifest_number' => 'tampered']);
        } finally {
            $context->forget();
        }
    }

    private function createMaster(Company $company, Branch $branch, User $actor): Master
    {
        $this->actingAs($actor)->post('/freight/masters', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'carrier_code' => 'EK',
            'flight_number' => 'EK332',
            'origin_airport' => 'DXB',
            'destination_airport' => 'MNL',
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return Master::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }

    private function unitFor(Company $company, int $masterId): LoadUnit
    {
        return $this->unitsFor($company, $masterId)->first();
    }

    private function unitsFor(Company $company, int $masterId)
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return LoadUnit::query()->where('master_id', $masterId)->orderByDesc('id')->get();
        } finally {
            $context->forget();
        }
    }

    private function packageFor(Company $company, int $shipmentId): ShipmentPackage
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return ShipmentPackage::query()->where('shipment_id', $shipmentId)->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
