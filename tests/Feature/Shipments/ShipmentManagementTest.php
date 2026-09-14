<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Company;
use App\Models\Shipment;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ShipmentManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_platform_admin_can_book_in_the_selected_company_but_not_another_company(): void
    {
        $company = $this->createCompany('ACT');
        $branch = $this->createBranch($company, 'DXB');
        $other = $this->createCompany('OTH');
        $otherBranch = $this->createBranch($other, 'MNL');
        $admin = \App\Models\User::factory()->create(['company_id' => null, 'branch_id' => null, 'is_platform_admin' => true, 'status' => \App\Enums\UserStatus::Active]);
        $this->actingAs($admin)->post('/platform/act-as/'.$company->id)->assertRedirect();
        $this->post('/shipments', $this->basePayload($branch->id))->assertSessionHasNoErrors();
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));
        self::assertSame($company->id, $shipment->company_id);
        $this->post('/shipments/'.$shipment->id.'/transitions', ['status' => 'booked'])->assertSessionHasNoErrors();
        $this->post('/shipments', $this->basePayload($otherBranch->id))->assertSessionHasErrors('branch_id');
    }

    private function basePayload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [
                ['weight_kg' => 5, 'length' => 50, 'width' => 40, 'height' => 30],
            ],
        ];
    }

    public function test_receipt_suffix_keeps_prefix_and_rejects_duplicates_including_deleted_shipments(): void
    {
        $company = $this->createCompany('REC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $payload = [...$this->basePayload($branch->getKey()), 'tracking_mode' => 'suffix', 'tracking_suffix' => 'OTHER-0012'];
        $this->actingAs($actor)->post('/shipments', $payload)->assertSessionHasNoErrors();
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));
        self::assertSame('REC-DXB-OTHER-0012', $shipment->tracking_number);
        self::assertSame('REC-DXB-OTHER-0012-01', $shipment->packages()->withoutGlobalScopes()->firstOrFail()->barcode);
        $this->actingAs($actor)->post('/shipments', $payload)->assertSessionHasErrors('tracking_suffix');
        $shipment->delete();
        $this->actingAs($actor)->post('/shipments', $payload)->assertSessionHasErrors('tracking_suffix');
    }

    public function test_automatic_numbering_skips_manually_used_sequence_numbers(): void
    {
        $company = $this->createCompany('SEQ');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $this->actingAs($actor)->post('/shipments', [...$this->basePayload($branch->getKey()), 'tracking_mode' => 'suffix', 'tracking_suffix' => '00000001'])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()))->assertSessionHasNoErrors();
        self::assertSame('SEQ-DXB-00000002', $this->findShipment($company, fn ($query) => $query->latest('id'))->tracking_number);
    }

    public function test_manual_suffix_requires_a_safe_reference_within_total_length(): void
    {
        $company = $this->createCompany('VAL');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        foreach (['', 'INV/123', str_repeat('A', 40)] as $suffix) {
            $this->actingAs($actor)->post('/shipments', [...$this->basePayload($branch->getKey()), 'tracking_mode' => 'suffix', 'tracking_suffix' => $suffix])->assertSessionHasErrors('tracking_suffix');
        }
    }

    public function test_a_user_with_permission_can_create_a_shipment_with_a_collision_safe_tracking_number(): void
    {
        $company = $this->createCompany('SHA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload($branch->getKey()))
            ->assertRedirect();

        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));
        self::assertSame("{$company->code}-DXB-00000001", $shipment->tracking_number);
        self::assertSame('draft', $shipment->status);
        self::assertSame(1, $shipment->package_count);
        self::assertEqualsWithDelta(10.0, (float) $shipment->volumetric_weight_kg, 0.001);
        self::assertEqualsWithDelta(10.0, (float) $shipment->chargeable_weight_kg, 0.001);
    }

    public function test_a_second_shipment_in_the_same_branch_gets_the_next_sequential_number(): void
    {
        $company = $this->createCompany('SHB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()));
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()));

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $numbers = Shipment::query()->orderBy('id')->pluck('tracking_number')->all();
        } finally {
            $context->forget();
        }

        self::assertSame(["{$company->code}-DXB-00000001", "{$company->code}-DXB-00000002"], $numbers);
    }

    public function test_a_shipment_requires_exactly_one_consignor_and_one_consignee(): void
    {
        $company = $this->createCompany('SHC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'] = [['role' => 'consignor', 'name' => 'Only Consignor']];

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors('parties');
    }

    public function test_a_user_without_permission_cannot_create_a_shipment(): void
    {
        $company = $this->createCompany('SHD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload($branch->getKey()))
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_another_companys_shipment(): void
    {
        $companyA = $this->createCompany('SHE');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['shipments.view', 'shipments.manage']);

        $companyB = $this->createCompany('SHF');
        $branchB = $this->createBranch($companyB, 'MNL');
        $actorB = $this->createUser($companyB, $branchB);
        $this->grantPermissions($actorB, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actorB)->post('/shipments', $this->basePayload($branchB->getKey()));
        $shipmentB = $this->findShipment($companyB, fn ($query) => $query->latest('id'));

        $this->actingAs($actorA)->get("/shipments/{$shipmentB->getKey()}")->assertNotFound();
    }

    public function test_branch_scoped_staff_cannot_view_a_shipment_from_a_sibling_branch(): void
    {
        $company = $this->createCompany('SHG');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['shipments.view', 'shipments.manage']);
        $branchStaff = $this->createUser($company, $branchA);
        $this->grantPermissions($branchStaff, ['shipments.view']);

        $this->actingAs($manager)->post('/shipments', $this->basePayload($branchB->getKey()));
        $shipment = $this->findShipment($company, fn ($query) => $query->where('branch_id', $branchB->getKey()));

        $this->actingAs($branchStaff)->get("/shipments/{$shipment->getKey()}")->assertForbidden();
    }

    public function test_a_booked_shipments_own_details_can_still_be_corrected(): void
    {
        $company = $this->createCompany('SHH');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);

        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()));
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'booked']);

        // Once booked and past its company's own initial status, this is no
        // longer a data-entry correction on the same footing as a
        // destination typo — it's the number already on a printed label.
        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", [
                'branch_id' => $branch->getKey(),
                'mode' => 'sea',
                'destination_country_code' => 'PH',
                'destination_city' => 'Cebu',
                'tracking_number' => 'HAND-TYPED-0001',
            ])
            ->assertSessionHasErrors('tracking_number');

        // Everything else about the shipment is still fair game to fix.
        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", [
                'branch_id' => $branch->getKey(),
                'mode' => 'sea',
                'destination_country_code' => 'PH',
                'destination_city' => 'Cebu',
                'carrier_code' => 'mock',
                'declared_value' => 250,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $shipment = $this->findShipment($company, fn ($query) => $query->whereKey($shipment->getKey()));
        self::assertSame('sea', $shipment->mode->value);
        self::assertSame('Cebu', $shipment->destination_city);
        self::assertSame('mock', $shipment->carrier_code);
        self::assertSame('250.00', $shipment->declared_value);
    }

    public function test_a_company_cannot_delete_another_companys_shipment(): void
    {
        $company = $this->createCompany('SHJ');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()));
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));

        $otherCompany = $this->createCompany('SHK');
        $otherBranch = $this->createBranch($otherCompany, 'AUH');
        $intruder = $this->createUser($otherCompany, $otherBranch);
        $this->grantPermissions($intruder, ['shipments.view', 'shipments.manage']);

        $this->actingAs($intruder)->delete("/shipments/{$shipment->getKey()}")->assertNotFound();
    }

    public function test_a_user_without_permission_cannot_delete_a_shipment(): void
    {
        $company = $this->createCompany('SHL');
        $branch = $this->createBranch($company, 'DXB');
        $manager = $this->createUser($company, $branch);
        $this->grantPermissions($manager, ['shipments.view', 'shipments.manage']);
        $this->actingAs($manager)->post('/shipments', $this->basePayload($branch->getKey()));
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));

        $viewer = $this->createUser($company, $branch);
        $this->grantPermissions($viewer, ['shipments.view']);

        $this->actingAs($viewer)->delete("/shipments/{$shipment->getKey()}")->assertForbidden();
    }

    public function test_a_shipment_can_be_deleted_and_disappears_from_the_listing(): void
    {
        $company = $this->createCompany('SHM');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()));
        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));

        $this->actingAs($actor)->delete("/shipments/{$shipment->getKey()}")->assertRedirect('/shipments');

        $this->actingAs($actor)->get("/shipments/{$shipment->getKey()}")->assertNotFound();
        $this->actingAs($actor)->get('/shipments')->assertInertia(fn ($page) => $page->has('shipments.data', 0));

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertNotNull(Shipment::withTrashed()->find($shipment->getKey())?->deleted_at);
        } finally {
            $context->forget();
        }
    }

    public function test_a_shipment_can_be_booked_with_a_known_carrier_but_rejects_an_unknown_one(): void
    {
        $company = $this->createCompany('SHI');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['carrier_code'] = 'not-a-real-carrier';

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors('carrier_code');

        $payload['carrier_code'] = 'mock';

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertRedirect();

        $shipment = $this->findShipment($company, fn ($query) => $query->latest('id'));
        self::assertSame('mock', $shipment->carrier_code);
    }

    private function findShipment(Company $company, \Closure $constrain): Shipment
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $constrain(Shipment::query())->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
