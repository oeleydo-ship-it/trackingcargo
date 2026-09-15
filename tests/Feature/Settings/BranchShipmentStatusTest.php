<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ShipmentStatusRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\User;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\ShipmentTransitionService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Per-branch shipment workflows: every branch uses the company default until
 * it is customised, after which it runs its own copy.
 */
final class BranchShipmentStatusTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private User $admin;

    private Branch $dubai;

    private Branch $manila;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('BSW');
        $this->dubai = $this->createBranch($this->company, 'DXB');
        $this->manila = $this->createBranch($this->company, 'MNL');
        $this->admin = $this->createUser($this->company);
        $this->grantPermissions($this->admin, ['statuses.view', 'statuses.manage', 'shipments.view', 'shipments.manage', 'tracking.view', 'tracking.update']);
    }

    public function test_a_branch_uses_the_company_default_until_customised(): void
    {
        $this->actingAs($this->admin)
            ->get("/settings/shipment-statuses?branch={$this->manila->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedBranchId', $this->manila->getKey())
                ->where('inheritsDefault', true)
                ->where('branches.1.customised', false));

        self::assertCount(0, $this->branchRows($this->manila));
    }

    public function test_customising_copies_the_default_workflow_with_the_same_codes_and_flow(): void
    {
        $this->customise($this->manila);

        $copy = $this->branchRows($this->manila);
        $defaults = $this->branchRows(null);

        self::assertEqualsCanonicalizing($defaults->pluck('code')->all(), $copy->pluck('code')->all());
        self::assertSame(['booked', 'cancelled'], $this->flowFrom('draft', $this->manila));

        $this->actingAs($this->admin)
            ->get("/settings/shipment-statuses?branch={$this->manila->getKey()}")
            ->assertInertia(fn ($page) => $page->where('inheritsDefault', false));
    }

    public function test_renaming_a_branch_status_leaves_the_default_and_other_branches_alone(): void
    {
        $this->customise($this->manila);
        $manilaCustoms = $this->branchRows($this->manila)->firstWhere('code', 'at_customs');

        $this->actingAs($this->admin)
            ->patch("/settings/shipment-statuses/{$manilaCustoms->getKey()}", ['name' => 'Sa Customs', 'color' => 'violet'])
            ->assertSessionHasNoErrors();

        $manilaShipment = $this->createShipment($this->company, $this->manila, ['status' => 'at_customs']);
        $dubaiShipment = $this->createShipment($this->company, $this->dubai, ['status' => 'at_customs']);

        $this->actingAs($this->admin)->get("/shipments/{$manilaShipment->getKey()}")
            ->assertInertia(fn ($page) => $page->where('shipment.shipment_status.name', 'Sa Customs'));

        $this->actingAs($this->admin)->get("/shipments/{$dubaiShipment->getKey()}")
            ->assertInertia(fn ($page) => $page->where('shipment.shipment_status.name', 'At customs'));

        self::assertSame('At customs', $this->branchRows(null)->firstWhere('code', 'at_customs')->name);
    }

    public function test_a_status_added_to_a_branch_is_offered_only_to_that_branchs_shipments(): void
    {
        $this->customise($this->manila);
        $this->addBranchStatus($this->manila, 'Sa bodega', from: 'received');

        $manilaShipment = $this->createShipment($this->company, $this->manila, ['status' => 'received']);
        $dubaiShipment = $this->createShipment($this->company, $this->dubai, ['status' => 'received']);

        $this->actingAs($this->admin)->get("/shipments/{$manilaShipment->getKey()}")
            ->assertInertia(fn ($page) => $page->where('allowedTransitions', fn ($options) => collect($options)->pluck('label')->contains('Sa bodega')));

        $this->actingAs($this->admin)->get("/shipments/{$dubaiShipment->getKey()}")
            ->assertInertia(fn ($page) => $page->where('allowedTransitions', fn ($options) => ! collect($options)->pluck('label')->contains('Sa bodega')));

        $this->actingAs($this->admin)
            ->post("/shipments/{$manilaShipment->getKey()}/transitions", ['status' => 'sa_bodega'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post("/shipments/{$dubaiShipment->getKey()}/transitions", ['status' => 'sa_bodega'])
            ->assertSessionHasErrors('status');

        $this->withTenant(function () use ($manilaShipment, $dubaiShipment): void {
            self::assertSame('sa_bodega', $manilaShipment->refresh()->status);
            self::assertSame('received', $dubaiShipment->refresh()->status);
        });
    }

    public function test_a_status_cannot_be_added_to_a_branch_that_is_not_customised(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/shipment-statuses', ['name' => 'Sa bodega', 'color' => 'amber', 'branch_id' => $this->manila->getKey()])
            ->assertSessionHasErrors('status');

        self::assertCount(0, $this->branchRows($this->manila));
    }

    public function test_a_new_shipment_starts_in_its_branchs_own_starting_status(): void
    {
        $this->customise($this->manila);
        $intake = $this->addBranchStatus($this->manila, 'Intake');

        $this->actingAs($this->admin)
            ->patch("/settings/shipment-statuses/{$intake->getKey()}", ['name' => 'Intake', 'color' => 'cyan', 'is_initial' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/shipments', $this->booking($this->manila))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/shipments', $this->booking($this->dubai))->assertSessionHasNoErrors();

        $this->withTenant(function (): void {
            self::assertSame('intake', Shipment::query()->where('branch_id', $this->manila->getKey())->sole()->status);
            self::assertSame('draft', Shipment::query()->where('branch_id', $this->dubai->getKey())->sole()->status);
        });
    }

    public function test_system_moves_use_the_branchs_own_status_for_a_role(): void
    {
        $this->customise($this->manila);
        $shipment = $this->createShipment($this->company, $this->manila, ['status' => 'in_transit']);

        $this->withTenant(function () use ($shipment): void {
            app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::AtCustoms, $this->admin);

            $status = app(ShipmentStatusRepository::class)->statusOf($shipment->refresh());

            self::assertSame('at_customs', $shipment->status);
            self::assertSame($this->manila->getKey(), $status->branch_id, 'The branch copy, not the default row, should apply.');
        });
    }

    public function test_reset_is_refused_while_shipments_use_a_branch_only_status_and_allowed_after(): void
    {
        $this->customise($this->manila);
        $this->addBranchStatus($this->manila, 'Sa bodega', from: 'received');
        $shipment = $this->createShipment($this->company, $this->manila, ['status' => 'sa_bodega']);

        $this->actingAs($this->admin)
            ->delete("/settings/shipment-statuses/branches/{$this->manila->getKey()}/customise")
            ->assertSessionHas('error');

        self::assertNotEmpty($this->branchRows($this->manila));

        $this->withTenant(fn () => $shipment->forceFill(['status' => 'received'])->save());

        $this->actingAs($this->admin)
            ->delete("/settings/shipment-statuses/branches/{$this->manila->getKey()}/customise")
            ->assertSessionHas('success');

        self::assertCount(0, $this->branchRows($this->manila));

        $this->actingAs($this->admin)->get("/shipments/{$shipment->getKey()}")
            ->assertInertia(fn ($page) => $page->where('shipment.shipment_status.name', 'Received at origin'));
    }

    public function test_a_default_status_counts_only_shipments_that_use_the_default(): void
    {
        $this->customise($this->manila);
        $this->createShipment($this->company, $this->manila, ['status' => 'received']);

        $this->actingAs($this->admin)
            ->get('/settings/shipment-statuses')
            ->assertInertia(fn ($page) => $page->where('statuses', fn ($rows) => collect($rows)->firstWhere('code', 'received')['shipment_count'] === 0));

        $this->actingAs($this->admin)
            ->get("/settings/shipment-statuses?branch={$this->manila->getKey()}")
            ->assertInertia(fn ($page) => $page->where('statuses', fn ($rows) => collect($rows)->firstWhere('code', 'received')['shipment_count'] === 1));
    }

    public function test_a_transition_cannot_point_into_another_branchs_workflow(): void
    {
        $this->customise($this->manila);
        $defaultBooked = $this->branchRows(null)->firstWhere('code', 'booked');
        $manilaReceived = $this->branchRows($this->manila)->firstWhere('code', 'received');

        $this->actingAs($this->admin)
            ->patch("/settings/shipment-statuses/{$defaultBooked->getKey()}", [
                'name' => $defaultBooked->name,
                'color' => $defaultBooked->color,
                'transitions_to' => [$manilaReceived->getKey()],
            ])
            ->assertSessionHasNoErrors();

        self::assertSame([], $this->flowFrom('booked', null));
    }

    public function test_public_tracking_shows_the_branchs_status_name(): void
    {
        $this->customise($this->manila);
        $manilaCustoms = $this->branchRows($this->manila)->firstWhere('code', 'at_customs');
        $this->actingAs($this->admin)->patch("/settings/shipment-statuses/{$manilaCustoms->getKey()}", ['name' => 'Sa Customs', 'color' => 'violet']);
        $shipment = $this->createShipment($this->company, $this->manila, ['status' => 'at_customs']);

        $this->post('/logout');

        $this->get("/track/{$shipment->tracking_number}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipment.status_label', 'Sa Customs'));
    }

    private function customise(Branch $branch): void
    {
        $this->actingAs($this->admin)
            ->post("/settings/shipment-statuses/branches/{$branch->getKey()}/customise")
            ->assertSessionHas('success');
    }

    private function addBranchStatus(Branch $branch, string $name, ?string $from = null): ShipmentStatus
    {
        $this->actingAs($this->admin)
            ->post('/settings/shipment-statuses', ['name' => $name, 'color' => 'amber', 'branch_id' => $branch->getKey()])
            ->assertSessionHasNoErrors();

        $status = $this->branchRows($branch)->firstWhere('name', $name);

        if ($from !== null) {
            $source = $this->branchRows($branch)->firstWhere('code', $from);
            $targets = [...$this->flowIdsFrom($source), $status->getKey()];

            $this->actingAs($this->admin)
                ->patch("/settings/shipment-statuses/{$source->getKey()}", ['name' => $source->name, 'color' => $source->color, 'transitions_to' => $targets])
                ->assertSessionHasNoErrors();
        }

        return $status;
    }

    /** @return Collection<int, ShipmentStatus> */
    private function branchRows(?Branch $branch): Collection
    {
        return $this->withTenant(fn () => ShipmentStatus::query()->where('scope', $branch?->getKey() ?? 0)->ordered()->get());
    }

    /** @return list<string> */
    private function flowFrom(string $code, ?Branch $branch): array
    {
        return $this->withTenant(function () use ($code, $branch): array {
            $repository = app(ShipmentStatusRepository::class);
            $repository->forget();
            $from = $repository->scopeSet((int) $this->company->getKey(), $branch?->getKey() ?? 0)->firstWhere('code', $code);

            return $repository->allowedFrom($from)->pluck('code')->sort()->values()->all();
        });
    }

    /** @return list<int> */
    private function flowIdsFrom(ShipmentStatus $status): array
    {
        return $this->withTenant(fn () => $status->transitionsTo()->pluck('shipment_statuses.id')->map(fn ($id): int => (int) $id)->all());
    }

    private function booking(Branch $branch): array
    {
        return [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
        ];
    }

    private function withTenant(Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $this->company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
