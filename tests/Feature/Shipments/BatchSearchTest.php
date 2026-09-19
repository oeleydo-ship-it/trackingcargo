<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Enums\BatchStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ShipmentBatch;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Search and filters on the Batches list.
 */
final class BatchSearchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('BSA');
        $this->branch = $this->createBranch($this->company, 'DXB');
        $this->actor = $this->createUser($this->company);
        $this->grantPermissions($this->actor, ['batches.view', 'batches.manage', 'shipments.view', 'shipments.manage']);
    }

    private function asTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    private function open(string $number, array $overrides = [], ?Branch $branch = null, ?Company $company = null): ShipmentBatch
    {
        $company ??= $this->company;

        return $this->asTenant($company, fn (): ShipmentBatch => ShipmentBatch::query()->create([
            'branch_id' => ($branch ?? $this->branch)->getKey(),
            'batch_number' => $number,
            'status' => BatchStatus::Open,
            ...$overrides,
        ]));
    }

    /** The batch numbers the list shows for a query string, sorted. */
    private function listed(array $query = [], ?User $as = null): array
    {
        $numbers = [];

        $this->actingAs($as ?? $this->actor)
            ->get('/batches?'.http_build_query($query))
            ->assertOk()
            ->assertInertia(function ($page) use (&$numbers): void {
                $numbers = array_column($page->toArray()['props']['batches']['data'], 'batch_number');
            });

        sort($numbers);

        return $numbers;
    }

    public function test_search_finds_a_batch_by_number_reference_or_notes(): void
    {
        $this->open('BATCH-DXB-00001', ['reference' => 'Tuesday air consolidation']);
        $this->open('BATCH-DXB-00002', ['reference' => 'Sea run', 'notes' => 'Fragile glassware']);
        $this->open('BATCH-DXB-00003');

        self::assertSame(['BATCH-DXB-00002'], $this->listed(['q' => '00002']));
        self::assertSame(['BATCH-DXB-00001'], $this->listed(['q' => 'consolidation']));
        self::assertSame(['BATCH-DXB-00002'], $this->listed(['q' => 'glassware']));
        self::assertSame([], $this->listed(['q' => 'nothing like this']));
    }

    public function test_search_finds_a_batch_by_a_shipment_inside_it_or_by_who_opened_it(): void
    {
        $batch = $this->open('BATCH-DXB-00001', ['created_by' => $this->actor->getKey()]);
        $this->open('BATCH-DXB-00002');
        $this->createShipment($this->company, $this->branch, ['tracking_number' => 'BSA-INSIDE-77', 'batch_id' => $batch->getKey()]);

        self::assertSame(['BATCH-DXB-00001'], $this->listed(['q' => 'INSIDE-77']));
        self::assertSame(['BATCH-DXB-00001'], $this->listed(['q' => $this->actor->name]));
    }

    public function test_every_word_of_a_search_has_to_match_something(): void
    {
        $this->open('BATCH-DXB-00001', ['reference' => 'Tuesday air']);
        $this->open('BATCH-DXB-00002', ['reference' => 'Tuesday sea']);

        self::assertSame(['BATCH-DXB-00001', 'BATCH-DXB-00002'], $this->listed(['q' => 'tuesday']));
        self::assertSame(['BATCH-DXB-00002'], $this->listed(['q' => 'tuesday sea']));
    }

    public function test_wildcard_characters_in_a_search_are_taken_literally(): void
    {
        $this->open('BATCH-DXB-00001');

        self::assertSame([], $this->listed(['q' => '%']));
    }

    public function test_filters_narrow_by_status_and_branch(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $this->open('BATCH-DXB-OPEN');
        $this->open('BATCH-DXB-CLOSED', ['status' => BatchStatus::Closed]);
        $this->open('BATCH-AUH-OPEN', [], $auh);

        self::assertSame(['BATCH-DXB-CLOSED'], $this->listed(['status' => 'closed']));
        self::assertSame(['BATCH-AUH-OPEN', 'BATCH-DXB-OPEN'], $this->listed(['status' => 'open']));
        self::assertSame(['BATCH-AUH-OPEN'], $this->listed(['branch_id' => $auh->getKey()]));
        self::assertSame(['BATCH-DXB-OPEN'], $this->listed(['status' => 'open', 'branch_id' => $this->branch->getKey()]));
    }

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        foreach (['B-09' => '2026-03-09 23:59:59', 'B-10' => '2026-03-10 00:00:00', 'B-12' => '2026-03-12 23:59:59', 'B-13' => '2026-03-13 00:00:00'] as $number => $createdAt) {
            $batch = $this->open($number);
            DB::table('shipment_batches')->where('id', $batch->getKey())->update(['created_at' => $createdAt]);
        }

        self::assertSame(['B-10', 'B-12'], $this->listed(['from' => '2026-03-10', 'to' => '2026-03-12']));
    }

    public function test_the_applied_filters_are_handed_back_to_the_page(): void
    {
        $this->actingAs($this->actor)
            ->get('/batches?q=%20tuesday%20&status=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.q', 'tuesday')->where('filters.status', 'open')->where('filters.branch_id', ''));
    }

    public function test_an_unknown_status_is_rejected_rather_than_ignored(): void
    {
        $this->actingAs($this->actor)->get('/batches?status=archived')->assertSessionHasErrors('status');
    }

    public function test_a_user_who_may_not_view_batches_cannot_search_them(): void
    {
        $outsider = $this->createUser($this->company);

        $this->actingAs($outsider)->get('/batches?q=anything')->assertForbidden();
    }

    public function test_the_branch_filter_only_offers_branches_that_have_a_visible_batch(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $this->createBranch($this->company, 'SHJ');
        $this->open('BATCH-DXB-00001');
        $this->open('BATCH-AUH-00001', [], $auh);

        $this->actingAs($this->actor)->get('/batches')->assertInertia(fn ($page) => $page->has('filterOptions.branches', 2));
    }

    public function test_a_branch_scoped_user_cannot_reach_another_branchs_batches_by_searching(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $clerk = $this->createUser($this->company, $this->branch);
        $this->grantPermissions($clerk, ['batches.view']);
        $this->open('BATCH-DXB-MINE');
        $this->open('BATCH-AUH-THEIRS', [], $auh);

        self::assertSame(['BATCH-DXB-MINE'], $this->listed(as: $clerk));
        self::assertSame([], $this->listed(['q' => 'THEIRS'], $clerk));
        self::assertSame([], $this->listed(['branch_id' => $auh->getKey()], $clerk));

        $this->actingAs($clerk)->get('/batches')->assertInertia(fn ($page) => $page->has('filterOptions.branches', 1));
    }

    public function test_another_companys_batches_never_appear(): void
    {
        $other = $this->createCompany('BSB');
        $otherBranch = $this->createBranch($other, 'DXB');
        $this->open('BATCH-MINE');
        $this->open('BATCH-THEIRS', [], $otherBranch, $other);

        self::assertSame(['BATCH-MINE'], $this->listed());
        self::assertSame([], $this->listed(['q' => 'THEIRS']));
    }
}
