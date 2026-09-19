<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Enums\BatchStatus;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentStatus;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ShipmentBatchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @param callable(): mixed $callback */
    private function asTenant(Company $company, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    private function createBatch(Company $company, int $branchId, array $overrides = []): ShipmentBatch
    {
        return $this->asTenant($company, fn (): ShipmentBatch => ShipmentBatch::query()->create([
            'branch_id' => $branchId,
            'batch_number' => 'BATCH-'.random_int(100000, 999999),
            'status' => BatchStatus::Open,
            ...$overrides,
        ]));
    }

    public function test_a_user_with_permission_can_create_a_batch_with_a_generated_number(): void
    {
        $company = $this->createCompany('BAA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post('/batches', ['branch_id' => $branch->getKey(), 'reference' => 'Tuesday air'])
            ->assertRedirect();

        $batch = $this->asTenant($company, fn () => ShipmentBatch::query()->latest('id')->firstOrFail());
        self::assertSame('BATCH-DXB-00001', $batch->batch_number);
        self::assertSame(BatchStatus::Open, $batch->status);
        self::assertSame((int) $actor->getKey(), $batch->created_by);
    }

    public function test_batch_numbers_follow_the_companys_configured_pattern(): void
    {
        $company = $this->createCompany('BAB');
        $company->forceFill(['batch_number_format' => '{branch}-B{sequence}', 'batch_sequence_padding' => 3])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)->post('/batches', ['branch_id' => $branch->getKey()])->assertRedirect();
        $this->actingAs($actor)->post('/batches', ['branch_id' => $branch->getKey()])->assertRedirect();

        self::assertSame('DXB-B002', $this->asTenant($company, fn () => ShipmentBatch::query()->latest('id')->firstOrFail())->batch_number);
    }

    public function test_a_user_without_permission_cannot_create_a_batch(): void
    {
        $company = $this->createCompany('BAC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/batches', ['branch_id' => $branch->getKey()])
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_or_modify_another_companys_batch(): void
    {
        $companyA = $this->createCompany('BAD');
        $companyB = $this->createCompany('BAE');
        $branchB = $this->createBranch($companyB, 'BXB');
        $batchB = $this->createBatch($companyB, (int) $branchB->getKey());
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['batches.view', 'batches.manage', 'tracking.update']);

        $this->actingAs($actorA)->get("/batches/{$batchB->getKey()}")->assertNotFound();
        $this->actingAs($actorA)->delete("/batches/{$batchB->getKey()}")->assertNotFound();
        $this->actingAs($actorA)->post("/batches/{$batchB->getKey()}/transitions", ['status' => 'booked'])->assertNotFound();
    }

    public function test_platform_batch_details_use_only_the_owning_company_workflow(): void
    {
        $a = $this->createCompany('BQA');
        $b = $this->createCompany('BQB');
        $branchA = $this->createBranch($a, 'DXB');
        $branchB = $this->createBranch($b, 'MNL');
        $batchA = $this->createBatch($a, (int) $branchA->id);
        $batchB = $this->createBatch($b, (int) $branchB->id);
        $this->createShipment($a, $branchA, ['batch_id' => $batchA->id]);
        $this->createShipment($b, $branchB, ['batch_id' => $batchB->id]);
        $candidate = $this->createShipment($a, $branchA);
        $this->createShipment($b, $branchB);
        foreach ([$a, $b] as $company) {
            $this->asTenant($company, function () use ($company): void {
                ShipmentStatus::query()->where('code', 'received')->update(['name' => $company->code.' received']);
                ShipmentStatus::query()->where('code', 'in_transit')->update(['name' => $company->code.' transit']);
            });
        }
        $admin = $this->createUser($a);
        $admin->forceFill(['company_id' => null, 'is_platform_admin' => true])->save();

        // No acting-company session: this was the reported 500.
        foreach ([[$a, $batchA], [$b, $batchB]] as [$company, $batch]) {
            $this->actingAs($admin)->get('/batches/'.$batch->id)->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('shipments', 1)
                    ->where('shipments.0.company_id', $company->id)
                    ->where('shipments.0.shipment_status.name', $company->code.' received')
                    ->where('branchStatuses', fn (Collection $items): bool => $items->firstWhere('value', 'in_transit')['label'] === $company->code.' transit')
                    ->where('assignable', fn (Collection $items): bool => $company->id !== $a->id || $items->pluck('id')->all() === [$candidate->id]));
            self::assertFalse(app(TenantContext::class)->isResolved());
        }

        $this->withSession(['platform_acting_company_id' => $a->id])
            ->actingAs($admin)->get('/batches/'.$batchB->id)->assertNotFound();
    }

    public function test_company_users_cannot_override_their_tenant_using_session_or_input(): void
    {
        $a = $this->createCompany('BRA');
        $b = $this->createCompany('BRB');
        $branchA = $this->createBranch($a, 'DXB');
        $branchB = $this->createBranch($b, 'MNL');
        $batchA = $this->createBatch($a, (int) $branchA->id);
        $batchB = $this->createBatch($b, (int) $branchB->id);
        $actor = $this->createUser($a);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);
        $this->withSession(['platform_acting_company_id' => $b->id])->actingAs($actor);
        $this->get('/batches?company_id='.$b->id)->assertOk()->assertInertia(fn ($page) => $page
            ->has('batches.data', 1)->where('batches.data.0.id', $batchA->id)
            ->has('branches', 1)->where('branches.0.id', $branchA->id));
        $this->get('/batches/'.$batchB->id)->assertNotFound();
        $this->patch('/batches/'.$batchB->id, ['reference' => 'Changed'])->assertNotFound();
        $this->post('/batches', ['company_id' => $b->id, 'branch_id' => $branchB->id])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseHas('shipment_batches', ['id' => $batchB->id, 'reference' => null]);
    }

    public function test_a_shipment_can_be_booked_straight_into_an_existing_batch(): void
    {
        $company = $this->createCompany('BAF');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->shipmentPayload((int) $branch->getKey(), ['batch_id' => $batch->getKey()]))
            ->assertRedirect();

        $shipment = $this->asTenant($company, fn () => Shipment::query()->latest('id')->firstOrFail());
        self::assertSame((int) $batch->getKey(), $shipment->batch_id);
    }

    public function test_booking_can_open_a_new_batch_inline(): void
    {
        $company = $this->createCompany('BAG');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->shipmentPayload((int) $branch->getKey(), ['new_batch_reference' => 'Friday sea']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        [$shipment, $batch] = $this->asTenant($company, fn (): array => [
            Shipment::query()->latest('id')->firstOrFail(),
            ShipmentBatch::query()->latest('id')->firstOrFail(),
        ]);

        self::assertSame('Friday sea', $batch->reference);
        self::assertSame((int) $batch->getKey(), $shipment->batch_id);
    }

    public function test_a_shipment_cannot_join_a_batch_from_another_branch(): void
    {
        $company = $this->createCompany('BAH');
        $home = $this->createBranch($company, 'DXB');
        $other = $this->createBranch($company, 'AUH');
        $batch = $this->createBatch($company, (int) $other->getKey());
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->shipmentPayload((int) $home->getKey(), ['batch_id' => $batch->getKey()]))
            ->assertSessionHasErrors('batch_id');

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_a_closed_batch_rejects_new_members(): void
    {
        $company = $this->createCompany('BAI');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey(), ['status' => BatchStatus::Closed]);
        $shipment = $this->createShipment($company, $branch);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/shipments", ['shipments' => [$shipment->getKey()]])
            ->assertSessionHasErrors('shipments');

        self::assertNull($shipment->refresh()->batch_id);
    }

    public function test_shipments_can_be_added_to_and_removed_from_a_batch(): void
    {
        $company = $this->createCompany('BAJ');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $first = $this->createShipment($company, $branch);
        $second = $this->createShipment($company, $branch);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/shipments", ['shipments' => [$first->getKey(), $second->getKey()]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame(2, $this->asTenant($company, fn (): int => $batch->shipments()->count()));

        $this->actingAs($actor)
            ->delete("/batches/{$batch->getKey()}/shipments/{$first->getKey()}")
            ->assertRedirect();

        self::assertNull($first->refresh()->batch_id);
        self::assertSame((int) $batch->getKey(), $second->refresh()->batch_id);
    }

    public function test_a_bulk_update_advances_every_eligible_shipment_and_writes_one_tracking_event_each(): void
    {
        $company = $this->createCompany('BAK');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $first = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $second = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'tracking.update']);

        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/transitions", [
                'status' => 'in_transit',
                'location' => 'Dubai hub',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame('in_transit', $first->refresh()->status);
        self::assertSame('in_transit', $second->refresh()->status);
        self::assertSame('Dubai hub', $first->last_location);
        self::assertSame(1, $this->asTenant($company, fn (): int => $first->trackingEvents()->count()));
    }

    public function test_a_bulk_update_skips_shipments_that_cannot_make_the_transition_without_failing_the_rest(): void
    {
        $company = $this->createCompany('BAL');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $eligible = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $terminal = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey(), 'status' => 'cancelled']);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'tracking.update']);

        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/transitions", ['status' => 'in_transit'])
            ->assertRedirect();

        self::assertSame('in_transit', $eligible->refresh()->status);
        self::assertSame('cancelled', $terminal->refresh()->status);
        self::assertSame(0, $this->asTenant($company, fn (): int => $terminal->trackingEvents()->count()));
    }

    public function test_a_bulk_update_needs_the_tracking_permission_not_merely_batch_management(): void
    {
        $company = $this->createCompany('BAM');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/transitions", ['status' => 'in_transit'])
            ->assertForbidden();
    }

    public function test_deleting_a_batch_ungroups_its_shipments_rather_than_removing_them(): void
    {
        $company = $this->createCompany('BAN');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $shipment = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage']);

        $this->actingAs($actor)->delete("/batches/{$batch->getKey()}")->assertRedirect();

        self::assertNull($shipment->refresh()->batch_id);
        $this->assertDatabaseHas('shipments', ['id' => $shipment->getKey(), 'deleted_at' => null]);
    }

    public function test_the_batch_page_lists_the_branchs_whole_workflow_tagged_with_how_many_members_can_move_there(): void
    {
        $company = $this->createCompany('BAO');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        // 'received' allows in_transit/exception/cancelled; 'at_customs' allows
        // in_transit/out_for_delivery/exception. Every status in the branch's
        // workflow is offered, in workflow order, each with how many members it
        // would actually move — the rest are skipped by
        // ShipmentBatchService::bulkTransition() — so the admin can see who'd
        // move and who'd be skipped before submitting.
        $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $this->createShipment($company, $branch, ['batch_id' => $batch->getKey(), 'status' => 'at_customs']);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view']);

        $this->actingAs($actor)
            ->get("/batches/{$batch->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('branchStatuses', fn (Collection $statuses): bool => $statuses->pluck('value')->all() === [
                    'draft', 'booked', 'received', 'in_transit', 'at_customs', 'out_for_delivery', 'delivered', 'exception', 'cancelled', 'returned',
                ] && $statuses->pluck('applicable', 'value')->all() === [
                    'draft' => 0, 'booked' => 0, 'received' => 0, 'in_transit' => 2, 'at_customs' => 0,
                    'out_for_delivery' => 1, 'delivered' => 0, 'exception' => 2, 'cancelled' => 1, 'returned' => 0,
                ])
                ->etc());
    }

    public function test_a_status_the_workflow_does_not_allow_yet_is_skipped_with_a_reason_rather_than_applied(): void
    {
        $company = $this->createCompany('BAQ');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $shipment = $this->createShipment($company, $branch, ['batch_id' => $batch->getKey(), 'tracking_number' => 'BAQ-ONE']);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view', 'batches.manage', 'shipments.view', 'tracking.update']);

        // 'received' cannot go straight to 'delivered'.
        $this->actingAs($actor)
            ->post("/batches/{$batch->getKey()}/transitions", ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, "BAQ-ONE (can't move from Received at origin to Delivered)"));

        $this->assertDatabaseHas('shipments', ['id' => $shipment->getKey(), 'status' => 'received']);
    }

    public function test_a_custom_branch_workflow_is_what_the_batch_page_lists(): void
    {
        $company = $this->createCompany('BAR');
        $branch = $this->createBranch($company, 'DXB');
        $batch = $this->createBatch($company, (int) $branch->getKey());
        $this->createShipment($company, $branch, ['batch_id' => $batch->getKey()]);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['batches.view']);

        // A retired status is not offered.
        $this->asTenant($company, fn () => ShipmentStatus::query()->where('code', 'returned')->update(['is_active' => false]));

        $this->actingAs($actor)
            ->get("/batches/{$batch->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('branchStatuses', fn (Collection $statuses): bool => ! $statuses->pluck('value')->contains('returned') && $statuses->pluck('value')->contains('delivered'))
                ->etc());
    }

    public function test_the_batch_listing_never_shows_another_branchs_batches_to_scoped_staff(): void
    {
        $company = $this->createCompany('BAP');
        $home = $this->createBranch($company, 'DXB');
        $other = $this->createBranch($company, 'AUH');
        $this->createBatch($company, (int) $home->getKey(), ['reference' => 'Mine']);
        $this->createBatch($company, (int) $other->getKey(), ['reference' => 'Theirs']);
        $actor = $this->createUser($company, $home);
        $this->grantPermissions($actor, ['batches.view']);

        $this->actingAs($actor)
            ->get('/batches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('batches.data', 1)
                ->where('batches.data.0.reference', 'Mine')
                ->etc());
    }

    private function shipmentPayload(int $branchId, array $overrides = []): array
    {
        return [
            'branch_id' => $branchId,
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
            ...$overrides,
        ];
    }
}
