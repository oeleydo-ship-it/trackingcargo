<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Carriers maintained under Settings → Carriers, and the Carrier field on the
 * Operations booking and edit forms that picks from them.
 */
final class CarrierSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_manager_can_add_a_carrier(): void
    {
        [$company, $actor] = $this->companyWithManager('CRA');

        $this->actingAs($actor)
            ->post('/settings/carriers', [
                'name' => 'Emirates SkyCargo',
                'code' => 'ek',
                'modes' => ['air'],
                'contact_name' => 'Cargo desk',
                'contact_email' => 'cargo@example.test',
                'website' => 'https://example.test',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $carrier = $this->carrier($company, 'EK');

        self::assertSame('Emirates SkyCargo', $carrier->name);
        self::assertSame(['air'], $carrier->modes);
        self::assertTrue($carrier->is_active);
        self::assertNull($carrier->integration_code);
    }

    public function test_no_modes_selected_means_all_modes(): void
    {
        [$company, $actor] = $this->companyWithManager('CRB');

        $this->actingAs($actor)->post('/settings/carriers', ['name' => 'Aramex', 'code' => 'ARX', 'modes' => []])->assertSessionHasNoErrors();

        self::assertNull($this->carrier($company, 'ARX')->modes);
    }

    public function test_a_code_is_unique_within_a_company_but_not_across_companies(): void
    {
        [$company, $actor] = $this->companyWithManager('CRC');
        [, $otherActor] = $this->companyWithManager('CRD');

        $this->actingAs($actor)->post('/settings/carriers', ['name' => 'DHL', 'code' => 'DHL'])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/settings/carriers', ['name' => 'DHL again', 'code' => 'dhl'])->assertSessionHasErrors('code');

        $this->actingAs($otherActor)->post('/settings/carriers', ['name' => 'DHL', 'code' => 'DHL'])->assertSessionHasNoErrors();
    }

    public function test_only_registered_tracking_integrations_are_accepted(): void
    {
        [, $actor] = $this->companyWithManager('CRE');

        $this->actingAs($actor)
            ->post('/settings/carriers', ['name' => 'Maersk', 'code' => 'MSK', 'integration_code' => 'not-a-real-integration'])
            ->assertSessionHasErrors('integration_code');

        $this->actingAs($actor)
            ->post('/settings/carriers', ['name' => 'Maersk', 'code' => 'MSK', 'integration_code' => 'mock'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_viewer_can_see_the_list_but_not_change_it(): void
    {
        $company = $this->createCompany('CRF');
        $viewer = $this->createUser($company);
        $this->grantPermissions($viewer, ['shipments.view']);

        $this->actingAs($viewer)->get('/settings/carriers')->assertOk();
        $this->actingAs($viewer)->post('/settings/carriers', ['name' => 'DHL', 'code' => 'DHL'])->assertForbidden();
    }

    public function test_a_company_cannot_edit_another_companys_carrier(): void
    {
        [, $actor] = $this->companyWithManager('CRG');
        [$otherCompany] = $this->companyWithManager('CRH');
        $foreign = $this->makeCarrier($otherCompany, ['code' => 'DHL']);

        $this->actingAs($actor)
            ->patch("/settings/carriers/{$foreign->getKey()}", ['name' => 'Hijacked', 'code' => 'DHL'])
            ->assertNotFound();
    }

    public function test_the_booking_form_offers_only_active_carriers(): void
    {
        [$company, $actor] = $this->companyWithManager('CRI');
        $this->makeCarrier($company, ['name' => 'Active One', 'code' => 'ACT']);
        $this->makeCarrier($company, ['name' => 'Retired One', 'code' => 'OFF', 'is_active' => false]);

        $this->actingAs($actor)
            ->get('/shipments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('carriers', 1)
                ->where('carriers.0.code', 'ACT'));
    }

    public function test_booking_with_a_carrier_links_it_and_follows_its_tracking_integration(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRJ');
        $carrier = $this->makeCarrier($company, ['code' => 'EK', 'integration_code' => 'mock']);

        $this->actingAs($actor)
            ->post('/shipments', [...$this->bookingPayload($branch), 'carrier_id' => $carrier->getKey()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $shipment = $this->latestShipment($company);

        self::assertSame($carrier->getKey(), $shipment->carrier_id);
        self::assertSame('mock', $shipment->carrier_code, 'The tracking poller reads carrier_code, so it must follow the carrier.');
    }

    public function test_a_carrier_without_an_integration_leaves_the_shipment_untracked(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRK');
        $carrier = $this->makeCarrier($company, ['code' => 'LOC']);

        $this->actingAs($actor)->post('/shipments', [...$this->bookingPayload($branch), 'carrier_id' => $carrier->getKey()])->assertSessionHasNoErrors();

        self::assertNull($this->latestShipment($company)->carrier_code);
    }

    public function test_a_switched_off_carrier_cannot_be_chosen_for_a_new_booking(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRL');
        $carrier = $this->makeCarrier($company, ['code' => 'OFF', 'is_active' => false]);

        $this->actingAs($actor)
            ->post('/shipments', [...$this->bookingPayload($branch), 'carrier_id' => $carrier->getKey()])
            ->assertSessionHasErrors('carrier_id');
    }

    public function test_another_companys_carrier_cannot_be_chosen(): void
    {
        [, $actor, $branch] = $this->bookingSetup('CRM');
        [$otherCompany] = $this->companyWithManager('CRN');
        $foreign = $this->makeCarrier($otherCompany, ['code' => 'DHL']);

        $this->actingAs($actor)
            ->post('/shipments', [...$this->bookingPayload($branch), 'carrier_id' => $foreign->getKey()])
            ->assertSessionHasErrors('carrier_id');
    }

    public function test_editing_a_shipment_can_change_or_clear_its_carrier(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRO');
        $first = $this->makeCarrier($company, ['code' => 'ONE', 'integration_code' => 'mock']);
        $second = $this->makeCarrier($company, ['code' => 'TWO']);
        $shipment = $this->createShipment($company, $branch, ['carrier_id' => $first->getKey(), 'carrier_code' => 'mock']);

        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", [...$this->editPayload($branch), 'carrier_id' => $second->getKey()])
            ->assertSessionHasNoErrors();

        $shipment = $this->freshShipment($company, $shipment);
        self::assertSame($second->getKey(), $shipment->carrier_id);
        self::assertNull($shipment->carrier_code);

        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", [...$this->editPayload($branch), 'carrier_id' => ''])
            ->assertSessionHasNoErrors();

        $shipment = $this->freshShipment($company, $shipment);
        self::assertNull($shipment->carrier_id);
        self::assertNull($shipment->carrier_code);
    }

    public function test_a_shipment_keeps_a_carrier_that_was_switched_off_after_booking(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRP');
        $carrier = $this->makeCarrier($company, ['code' => 'OLD', 'is_active' => false]);
        $shipment = $this->createShipment($company, $branch, ['carrier_id' => $carrier->getKey()]);

        // Saving an unrelated correction with the same carrier still selected.
        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", [...$this->editPayload($branch), 'destination_city' => 'Cebu', 'carrier_id' => $carrier->getKey()])
            ->assertSessionHasNoErrors();

        self::assertSame($carrier->getKey(), $this->freshShipment($company, $shipment)->carrier_id);
    }

    public function test_changing_a_carriers_integration_updates_its_shipments(): void
    {
        [$company, $actor, $branch] = $this->bookingSetup('CRQ');
        $carrier = $this->makeCarrier($company, ['name' => 'Maersk', 'code' => 'MSK']);
        $shipment = $this->createShipment($company, $branch, ['carrier_id' => $carrier->getKey()]);

        $this->actingAs($actor)
            ->patch("/settings/carriers/{$carrier->getKey()}", ['name' => 'Maersk', 'code' => 'MSK', 'integration_code' => 'mock'])
            ->assertSessionHasNoErrors();

        self::assertSame('mock', $this->freshShipment($company, $shipment)->carrier_code);
    }

    public function test_switching_a_carrier_off_and_on(): void
    {
        [$company, $actor] = $this->companyWithManager('CRR');
        $carrier = $this->makeCarrier($company, ['code' => 'SW']);

        $this->actingAs($actor)->post("/settings/carriers/{$carrier->getKey()}/active", ['is_active' => false])->assertSessionHasNoErrors();
        self::assertFalse($this->carrier($company, 'SW')->is_active);

        $this->actingAs($actor)->post("/settings/carriers/{$carrier->getKey()}/active", ['is_active' => true])->assertSessionHasNoErrors();
        self::assertTrue($this->carrier($company, 'SW')->is_active);
    }

    /** @return array{Company, User} */
    private function companyWithManager(string $code): array
    {
        $company = $this->createCompany($code);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        return [$company, $actor];
    }

    /** @return array{Company, User, Branch} */
    private function bookingSetup(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        return [$company, $actor, $branch];
    }

    private function bookingPayload(Branch $branch): array
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

    private function editPayload(Branch $branch): array
    {
        return [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
        ];
    }

    private function makeCarrier(Company $company, array $attributes = []): Carrier
    {
        return $this->withTenant($company, fn () => Carrier::query()->create([
            'name' => 'Carrier',
            'code' => 'CAR',
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function carrier(Company $company, string $code): Carrier
    {
        return $this->withTenant($company, fn () => Carrier::query()->where('code', $code)->firstOrFail());
    }

    private function latestShipment(Company $company): Shipment
    {
        return $this->withTenant($company, fn () => Shipment::query()->latest('id')->firstOrFail());
    }

    private function freshShipment(Company $company, Shipment $shipment): Shipment
    {
        return $this->withTenant($company, fn () => Shipment::query()->findOrFail($shipment->getKey()));
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
}
