<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Enums\BatchStatus;
use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Search and filters on the Operations → Shipments list.
 */
final class ShipmentSearchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('SSA');
        $this->branch = $this->createBranch($this->company, 'DXB');
        // No branch of their own, so they see the whole company.
        $this->actor = $this->createUser($this->company);
        $this->grantPermissions($this->actor, ['shipments.view', 'shipments.manage']);
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

    private function book(string $trackingNumber, array $overrides = [], ?Branch $branch = null, ?Company $company = null): Shipment
    {
        return $this->createShipment($company ?? $this->company, $branch ?? $this->branch, ['tracking_number' => $trackingNumber, ...$overrides]);
    }

    private function addParty(Shipment $shipment, string $role, string $name): void
    {
        $this->asTenant($this->company, fn () => $shipment->parties()->create(['role' => $role, 'name' => $name]));
    }

    /** The tracking numbers the list shows for a query string, sorted. */
    private function listed(array $query = [], ?User $as = null): array
    {
        $numbers = [];

        $this->actingAs($as ?? $this->actor)
            ->get('/shipments?'.http_build_query($query))
            ->assertOk()
            ->assertInertia(function ($page) use (&$numbers): void {
                $numbers = array_column($page->toArray()['props']['shipments']['data'], 'tracking_number');
            });

        sort($numbers);

        return $numbers;
    }

    public function test_the_list_is_unfiltered_when_no_search_is_given(): void
    {
        $this->book('SSA-ONE');
        $this->book('SSA-TWO');

        self::assertSame(['SSA-ONE', 'SSA-TWO'], $this->listed());
    }

    public function test_search_finds_a_shipment_by_part_of_its_tracking_number(): void
    {
        $this->book('SSA-DXB-00000123');
        $this->book('SSA-DXB-00000999');

        self::assertSame(['SSA-DXB-00000123'], $this->listed(['q' => '0123']));
        self::assertSame([], $this->listed(['q' => 'NOPE']));
    }

    public function test_search_finds_a_shipment_by_either_party(): void
    {
        $consignor = $this->book('SSA-A');
        $consignee = $this->book('SSA-B');
        $this->book('SSA-C');
        $this->addParty($consignor, 'consignor', 'Aisha Rahman');
        $this->addParty($consignee, 'consignee', 'Aisha Rahman Trading');

        self::assertSame(['SSA-A', 'SSA-B'], $this->listed(['q' => 'rahman']));
    }

    public function test_search_finds_a_shipment_by_its_customer(): void
    {
        $customer = $this->createCustomer($this->company, 'Angie Lyca');
        $this->book('SSA-A', ['customer_id' => $customer->getKey()]);
        $this->book('SSA-B');

        self::assertSame(['SSA-A'], $this->listed(['q' => 'angie']));
    }

    public function test_search_finds_a_shipment_by_destination_city_batch_or_carrier(): void
    {
        $this->book('SSA-CEBU', ['destination_city' => 'Cebu']);
        $this->book('SSA-OTHER', ['destination_city' => 'Manila']);

        $batch = $this->asTenant($this->company, fn () => ShipmentBatch::query()->create([
            'branch_id' => $this->branch->getKey(),
            'batch_number' => 'BATCH-DXB-00042',
            'reference' => 'Tuesday consolidation',
            'status' => BatchStatus::Open,
        ]));
        $this->book('SSA-BATCHED', ['batch_id' => $batch->getKey()]);

        $carrier = $this->asTenant($this->company, fn () => Carrier::query()->create(['name' => 'Emirates SkyCargo', 'code' => 'EK', 'modes' => ['air'], 'is_active' => true]));
        $this->book('SSA-CARRIED', ['carrier_id' => $carrier->getKey()]);

        self::assertSame(['SSA-CEBU'], $this->listed(['q' => 'cebu']));
        self::assertSame(['SSA-BATCHED'], $this->listed(['q' => 'consolidation']));
        self::assertSame(['SSA-CARRIED'], $this->listed(['q' => 'skycargo']));
    }

    public function test_every_word_of_a_search_has_to_match_something(): void
    {
        $match = $this->book('SSA-A', ['destination_city' => 'Manila']);
        $wrongCity = $this->book('SSA-B', ['destination_city' => 'Cebu']);
        $this->addParty($match, 'consignee', 'Acme Freight');
        $this->addParty($wrongCity, 'consignee', 'Acme Freight');

        self::assertSame(['SSA-A', 'SSA-B'], $this->listed(['q' => 'acme']));
        self::assertSame(['SSA-A'], $this->listed(['q' => 'acme manila']));
        self::assertSame([], $this->listed(['q' => 'acme dubai']));
    }

    public function test_wildcard_characters_in_a_search_are_taken_literally(): void
    {
        $this->book('SSA-A');
        $this->book('SSA-B');

        self::assertSame([], $this->listed(['q' => '%']));
        self::assertSame([], $this->listed(['q' => '_']));
    }

    public function test_filters_narrow_by_status_mode_country_and_carrier(): void
    {
        $carrier = $this->asTenant($this->company, fn () => Carrier::query()->create(['name' => 'Maersk', 'code' => 'MSK', 'modes' => ['sea'], 'is_active' => true]));

        $this->book('SSA-AIR-PH', ['status' => 'in_transit', 'mode' => 'air', 'destination_country_code' => 'PH']);
        $this->book('SSA-SEA-PH', ['status' => 'in_transit', 'mode' => 'sea', 'destination_country_code' => 'PH', 'carrier_id' => $carrier->getKey()]);
        $this->book('SSA-SEA-AE', ['status' => 'delivered', 'mode' => 'sea', 'destination_country_code' => 'AE']);

        self::assertSame(['SSA-AIR-PH', 'SSA-SEA-PH'], $this->listed(['status' => 'in_transit']));
        self::assertSame(['SSA-SEA-AE', 'SSA-SEA-PH'], $this->listed(['mode' => 'sea']));
        self::assertSame(['SSA-AIR-PH', 'SSA-SEA-PH'], $this->listed(['country' => 'PH']));
        self::assertSame(['SSA-SEA-PH'], $this->listed(['carrier_id' => $carrier->getKey()]));
        self::assertSame(['SSA-SEA-PH'], $this->listed(['status' => 'in_transit', 'mode' => 'sea', 'country' => 'PH']));
    }

    public function test_filters_narrow_by_branch(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $this->book('SSA-DXB');
        $this->book('SSA-AUH', [], $auh);

        self::assertSame(['SSA-AUH'], $this->listed(['branch_id' => $auh->getKey()]));
    }

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        foreach (['SSA-MAR-09' => '2026-03-09 23:59:59', 'SSA-MAR-10' => '2026-03-10 00:00:00', 'SSA-MAR-12' => '2026-03-12 23:59:59', 'SSA-MAR-13' => '2026-03-13 00:00:00'] as $number => $createdAt) {
            $shipment = $this->book($number);
            DB::table('shipments')->where('id', $shipment->getKey())->update(['created_at' => $createdAt]);
        }

        self::assertSame(['SSA-MAR-10', 'SSA-MAR-12'], $this->listed(['from' => '2026-03-10', 'to' => '2026-03-12']));
        self::assertSame(['SSA-MAR-12', 'SSA-MAR-13'], $this->listed(['from' => '2026-03-11']));
        self::assertSame(['SSA-MAR-09', 'SSA-MAR-10'], $this->listed(['to' => '2026-03-10']));
    }

    public function test_search_and_filters_combine(): void
    {
        $a = $this->book('SSA-A', ['mode' => 'air']);
        $b = $this->book('SSA-B', ['mode' => 'sea']);
        $this->addParty($a, 'consignee', 'Acme');
        $this->addParty($b, 'consignee', 'Acme');

        self::assertSame(['SSA-B'], $this->listed(['q' => 'acme', 'mode' => 'sea']));
    }

    public function test_the_applied_filters_are_handed_back_to_the_page(): void
    {
        $this->book('SSA-A');

        $this->actingAs($this->actor)
            ->get('/shipments?q=%20acme%20&mode=sea&country=ph')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.q', 'acme')
                ->where('filters.mode', 'sea')
                ->where('filters.country', 'PH')
                ->where('filters.status', ''));
    }

    public function test_an_unknown_mode_is_rejected_rather_than_ignored(): void
    {
        $this->actingAs($this->actor)->get('/shipments?mode=teleport')->assertSessionHasErrors('mode');
    }

    public function test_a_user_who_may_not_view_shipments_cannot_search_them(): void
    {
        $outsider = $this->createUser($this->company);

        $this->actingAs($outsider)->get('/shipments?q=anything')->assertForbidden();
    }

    public function test_filter_options_only_offer_values_present_on_visible_shipments(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $this->createBranch($this->company, 'SHJ');
        $carrier = $this->asTenant($this->company, fn () => Carrier::query()->create(['name' => 'Emirates SkyCargo', 'code' => 'EK', 'modes' => ['air'], 'is_active' => true]));
        $this->asTenant($this->company, fn () => Carrier::query()->create(['name' => 'Never Used', 'code' => 'NU', 'modes' => ['air'], 'is_active' => true]));

        $this->book('SSA-A', ['status' => 'in_transit', 'destination_country_code' => 'PH', 'carrier_id' => $carrier->getKey()]);
        $this->book('SSA-B', ['status' => 'delivered', 'destination_country_code' => 'AE'], $auh);

        $this->actingAs($this->actor)->get('/shipments')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filterOptions.statuses.0.name', 'In transit')
            ->where('filterOptions.statuses.1.name', 'Delivered')
            ->has('filterOptions.statuses', 2)
            ->has('filterOptions.branches', 2)
            ->has('filterOptions.carriers', 1)
            ->where('filterOptions.carriers.0.name', 'Emirates SkyCargo')
            ->where('filterOptions.countries', ['AE', 'PH']));
    }

    public function test_a_branch_scoped_user_cannot_reach_another_branchs_shipments_by_searching(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $clerk = $this->createUser($this->company, $this->branch);
        $this->grantPermissions($clerk, ['shipments.view']);

        $this->book('SSA-MINE');
        $this->book('SSA-THEIRS', [], $auh);

        self::assertSame(['SSA-MINE'], $this->listed(as: $clerk));
        self::assertSame([], $this->listed(['q' => 'SSA-THEIRS'], $clerk));
        self::assertSame([], $this->listed(['branch_id' => $auh->getKey()], $clerk));

        // Nor do the dropdowns give away that the other branch exists.
        $this->actingAs($clerk)->get('/shipments')->assertInertia(fn ($page) => $page
            ->has('filterOptions.branches', 1)
            ->where('filterOptions.branches.0.id', $this->branch->getKey()));
    }

    public function test_a_customer_portal_login_can_only_search_their_own_shipments(): void
    {
        $portalUser = $this->createUser($this->company);
        $this->grantPermissions($portalUser, ['shipments.view']);
        $mine = $this->createCustomer($this->company, 'Portal Customer');
        $this->asTenant($this->company, fn () => $mine->forceFill(['portal_user_id' => $portalUser->getKey()])->save());
        $other = $this->createCustomer($this->company, 'Someone Else');

        $this->book('SSA-MINE', ['customer_id' => $mine->getKey(), 'destination_country_code' => 'PH']);
        $this->book('SSA-OTHER', ['customer_id' => $other->getKey(), 'destination_country_code' => 'AE']);

        self::assertSame(['SSA-MINE'], $this->listed(as: $portalUser));
        self::assertSame([], $this->listed(['q' => 'SSA-OTHER'], $portalUser));
        self::assertSame([], $this->listed(['q' => 'Someone Else'], $portalUser));
        self::assertSame([], $this->listed(['country' => 'AE'], $portalUser));

        $this->actingAs($portalUser)->get('/shipments')->assertInertia(fn ($page) => $page->where('filterOptions.countries', ['PH']));
    }

    public function test_another_companys_shipments_never_appear(): void
    {
        $other = $this->createCompany('SSB');
        $otherBranch = $this->createBranch($other, 'DXB');
        $this->book('SSA-MINE');
        $this->book('SSB-THEIRS', [], $otherBranch, $other);

        self::assertSame(['SSA-MINE'], $this->listed());
        self::assertSame([], $this->listed(['q' => 'SSB-THEIRS']));
    }

    public function test_a_soft_deleted_customer_still_leaves_their_shipments_searchable_by_party_name(): void
    {
        $customer = $this->createCustomer($this->company, 'Dummy Name');
        $shipment = $this->book('SSA-A', ['customer_id' => $customer->getKey()]);
        $this->addParty($shipment, 'consignor', 'Dummy Name');
        $this->asTenant($this->company, fn () => Customer::query()->whereKey($customer->getKey())->firstOrFail()->delete());

        self::assertSame(['SSA-A'], $this->listed(['q' => 'dummy']));
    }
}
