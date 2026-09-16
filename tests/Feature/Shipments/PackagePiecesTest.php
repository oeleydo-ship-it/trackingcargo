<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Box;
use App\Models\BoxSize;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Packages booked as several identical pieces, and the standard package sizes
 * (Mega Jumbo … Crate) added from Settings → Boxes.
 */
final class PackagePiecesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('PPT');
        $this->branch = $this->createBranch($this->company, 'DXB');
        $this->actor = $this->createUser($this->company, $this->branch);
        $this->grantPermissions($this->actor, ['shipments.view', 'shipments.manage']);
    }

    public function test_pieces_multiply_the_weight_and_the_package_count(): void
    {
        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([
                ['pieces' => 3, 'weight_kg' => 10, 'length' => 61, 'width' => 61, 'height' => 66],
                ['pieces' => 1, 'weight_kg' => 4, 'length' => 36, 'width' => 36, 'height' => 30],
            ]))
            ->assertSessionHasNoErrors();

        $shipment = $this->latestShipment();

        // 3 Jumbo + 1 Extra Small.
        self::assertSame(4, $shipment->package_count);
        self::assertEqualsWithDelta(34.0, (float) $shipment->declared_weight_kg, 0.001);

        // 61*61*66 / 6000 = 40.931 per piece, three of them; 36*36*30 / 6000 = 6.48.
        self::assertEqualsWithDelta(129.273, (float) $shipment->volumetric_weight_kg, 0.01);
        self::assertEqualsWithDelta(129.273, (float) $shipment->chargeable_weight_kg, 0.01);
    }

    public function test_a_package_row_defaults_to_one_piece(): void
    {
        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([['weight_kg' => 5]]))
            ->assertSessionHasNoErrors();

        $shipment = $this->latestShipment();

        self::assertSame(1, $this->withTenant(fn () => $shipment->packages()->sole()->pieces));
        self::assertSame(1, $shipment->package_count);
    }

    public function test_pieces_can_be_changed_after_booking(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking([['weight_kg' => 5]]));
        $shipment = $this->latestShipment();
        $package = $this->withTenant(fn () => $shipment->packages()->sole());

        $this->actingAs($this->actor)
            ->patch("/shipments/{$shipment->getKey()}/packages/{$package->getKey()}", ['pieces' => 4, 'weight_kg' => 5])
            ->assertSessionHasNoErrors();

        self::assertSame(4, $this->latestShipment()->package_count);
        self::assertEqualsWithDelta(20.0, (float) $this->latestShipment()->declared_weight_kg, 0.001);
    }

    public function test_more_than_999_pieces_is_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([['pieces' => 1000, 'weight_kg' => 5]]))
            ->assertSessionHasErrors('packages.0.pieces');
    }

    public function test_the_standard_sizes_can_be_added_from_settings(): void
    {
        $this->actingAs($this->actor)->post('/settings/boxes/standard-sizes')->assertRedirect()->assertSessionHas('success');

        $sizes = $this->withTenant(fn () => BoxSize::query()->whereHas('box', fn ($query) => $query->where('name', 'Package'))->get());

        self::assertCount(9, $sizes);
        self::assertEqualsCanonicalizing(
            ['Mega Jumbo', 'Jumbo', 'Large', 'Medium', 'Small', 'Extra Small', 'Odd Size', 'Drum', 'Crate'],
            $sizes->pluck('name')->all(),
        );

        $jumbo = $sizes->firstWhere('name', 'Jumbo');
        self::assertFalse($jumbo->is_custom);
        self::assertEqualsWithDelta(61.0, (float) $jumbo->length_cm, 0.001);
        self::assertEqualsWithDelta(66.0, (float) $jumbo->height_cm, 0.001);

        // A drum is stored as the box that contains it: diameter x diameter x height.
        $drum = $sizes->firstWhere('name', 'Drum');
        self::assertEqualsWithDelta(58.0, (float) $drum->width_cm, 0.001);
        self::assertEqualsWithDelta(94.0, (float) $drum->height_cm, 0.001);

        foreach (['Odd Size', 'Crate'] as $name) {
            $custom = $sizes->firstWhere('name', $name);
            self::assertTrue($custom->is_custom);
            self::assertNull($custom->length_cm);
        }
    }

    public function test_adding_the_standard_sizes_twice_does_not_duplicate_them(): void
    {
        $this->actingAs($this->actor)->post('/settings/boxes/standard-sizes');
        $this->actingAs($this->actor)->post('/settings/boxes/standard-sizes')->assertSessionHas('success');

        self::assertSame(9, $this->withTenant(fn () => BoxSize::query()->count()));
        self::assertSame(1, $this->withTenant(fn () => Box::query()->where('name', 'Package')->count()));
    }

    public function test_a_custom_size_requires_dimensions_on_the_package(): void
    {
        $this->actingAs($this->actor)->post('/settings/boxes/standard-sizes');
        $oddSize = $this->withTenant(fn () => BoxSize::query()->where('name', 'Odd Size')->firstOrFail());

        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([['weight_kg' => 5, 'box_size_id' => $oddSize->getKey()]]))
            ->assertSessionHasErrors('length');

        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([['weight_kg' => 5, 'box_size_id' => $oddSize->getKey(), 'length' => 80, 'width' => 40, 'height' => 40]]))
            ->assertSessionHasNoErrors();

        $shipment = $this->latestShipment();
        self::assertEqualsWithDelta(80.0, (float) $this->withTenant(fn () => $shipment->packages()->sole()->length_cm), 0.001);
    }

    public function test_a_size_with_fixed_dimensions_still_fills_them_in(): void
    {
        $this->actingAs($this->actor)->post('/settings/boxes/standard-sizes');
        $jumbo = $this->withTenant(fn () => BoxSize::query()->where('name', 'Jumbo')->firstOrFail());

        $this->actingAs($this->actor)
            ->post('/shipments', $this->booking([['weight_kg' => 5, 'box_size_id' => $jumbo->getKey()]]))
            ->assertSessionHasNoErrors();

        $shipment = $this->latestShipment();
        $package = $this->withTenant(fn () => $shipment->packages()->sole());

        self::assertEqualsWithDelta(61.0, (float) $package->length_cm, 0.001);
        self::assertEqualsWithDelta(66.0, (float) $package->height_cm, 0.001);
    }

    public function test_a_user_without_shipments_manage_cannot_add_the_standard_sizes(): void
    {
        $viewer = $this->createUser($this->company, $this->branch);
        $this->grantPermissions($viewer, ['shipments.view']);

        $this->actingAs($viewer)->post('/settings/boxes/standard-sizes')->assertForbidden();

        self::assertSame(0, $this->withTenant(fn () => BoxSize::query()->count()));
    }

    /** @param list<array<string, mixed>> $packages */
    private function booking(array $packages): array
    {
        return [
            'branch_id' => $this->branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => $packages,
        ];
    }

    private function latestShipment(): Shipment
    {
        return $this->withTenant(fn () => Shipment::query()->latest('id')->firstOrFail());
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
