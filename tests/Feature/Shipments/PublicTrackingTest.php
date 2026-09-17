<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Company;
use App\Models\Shipment;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class PublicTrackingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_anonymous_visitor_can_track_a_shipment_by_number(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $response = $this->get("/track/{$shipment->tracking_number}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('shipment.tracking_number', $shipment->tracking_number)
            ->where('shipment.status', 'in_transit')
            ->has('shipment.events', 2));
    }

    public function test_the_bare_track_url_is_a_blank_search_page_usable_by_any_customer(): void
    {
        $response = $this->get('/track');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('trackingNumber', '')
            ->where('shipment', null));
    }

    public function test_the_bare_embed_url_is_a_blank_search_widget(): void
    {
        $response = $this->get('/track/embed');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/TrackingEmbed')
            ->where('trackingNumber', '')
            ->where('shipment', null));
    }

    public function test_an_unknown_tracking_number_returns_no_shipment_data(): void
    {
        $response = $this->get('/track/DOES-NOT-EXIST-00000001');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('shipment', null));
    }

    public function test_public_tracking_never_exposes_private_fields(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $response = $this->get("/track/{$shipment->tracking_number}");

        $page = $response->viewData('page')['props'];
        $shipmentData = json_encode($page['shipment']);

        // Internal (non-public) tracking notes and the shipment's commercial and
        // tenancy columns must never reach an anonymous visitor. How much of the
        // parties shows is a company setting, covered separately below.
        self::assertStringNotContainsString('Internal warehouse note', $shipmentData);
        self::assertArrayNotHasKey('declared_value', $page['shipment']);
        self::assertArrayNotHasKey('company_id', $page['shipment']);
        self::assertArrayNotHasKey('customer_id', $page['shipment']);
    }

    public function test_by_default_both_parties_show_in_full_with_only_the_senders_phone_withheld(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $this->get("/track/{$shipment->tracking_number}")
            ->assertInertia(fn ($page) => $page
                ->where('shipment.sender.name', 'Consignor One')
                ->where('shipment.sender.phone', null)
                ->where('shipment.sender.email', null)
                ->where('shipment.receiver.name', 'Consignee One')
                ->where('shipment.receiver.address_lines', ['24 Mabini Street'])
                ->where('shipment.receiver.city', 'Manila')
                ->where('shipment.receiver.country_code', 'PH')
                ->where('shipment.receiver.phone', '+639170000002')
                ->where('shipment.receiver.email', null));
    }

    public function test_a_company_can_pull_every_party_field_back_to_partial_or_hidden(): void
    {
        $shipment = $this->bookedShipmentWithEvent();
        $this->setVisibility($shipment, [
            'sender' => ['name' => 'masked', 'address' => 'masked', 'phone' => 'masked', 'email' => 'masked'],
            'receiver' => ['name' => 'hidden', 'address' => 'hidden', 'phone' => 'hidden', 'email' => 'hidden'],
        ]);

        $response = $this->get("/track/{$shipment->tracking_number}");

        $response->assertInertia(fn ($page) => $page
            ->where('shipment.sender.name', 'Consignor O.')
            ->where('shipment.sender.phone', '••••••••0001')
            ->where('shipment.sender.email', 'c•••r@example.test')
            ->where('shipment.receiver', null));

        // Hidden and partial must hold in the payload, not merely in the rendered
        // card: nothing withheld may appear anywhere in the props.
        $shipmentData = json_encode($response->viewData('page')['props']['shipment']);
        self::assertStringNotContainsString('Consignor One', $shipmentData);
        self::assertStringNotContainsString('Consignee One', $shipmentData);
        self::assertStringNotContainsString('24 Mabini Street', $shipmentData);
        self::assertStringNotContainsString('971500000001', $shipmentData);
        self::assertStringNotContainsString('639170000002', $shipmentData);
    }

    public function test_a_partial_address_gives_city_and_country_but_never_the_street(): void
    {
        $shipment = $this->bookedShipmentWithEvent();
        $this->setVisibility($shipment, [
            'receiver' => ['name' => 'full', 'address' => 'masked', 'phone' => 'hidden', 'email' => 'hidden'],
        ]);

        $this->get("/track/{$shipment->tracking_number}")
            ->assertInertia(fn ($page) => $page
                ->where('shipment.receiver.address_lines', [])
                ->where('shipment.receiver.city', 'Manila')
                ->where('shipment.receiver.country_code', 'PH'));
    }

    /**
     * An unrecognised or missing entry must fall back to that field's default
     * rather than throwing or silently showing everything.
     */
    public function test_a_malformed_stored_setting_falls_back_to_the_default_for_that_field(): void
    {
        $shipment = $this->bookedShipmentWithEvent();
        $this->setVisibility($shipment, ['sender' => ['name' => 'not-a-level']]);

        $this->get("/track/{$shipment->tracking_number}")
            ->assertInertia(fn ($page) => $page
                ->where('shipment.sender.name', 'Consignor One')
                ->where('shipment.sender.phone', null));
    }

    /** @param array<string, array<string, string>> $parties */
    private function setVisibility(Shipment $shipment, array $parties): void
    {
        Company::query()->whereKey($shipment->company_id)->update([
            'public_tracking_parties' => json_encode($parties),
        ]);
    }

    public function test_the_embed_view_renders_the_same_shipment_with_no_site_navigation(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $response = $this->get("/track/{$shipment->tracking_number}/embed");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/TrackingEmbed')
            ->where('shipment.tracking_number', $shipment->tracking_number)
            ->where('shipment.status', 'in_transit'));
    }

    public function test_a_non_public_tracking_event_is_hidden_from_the_public_timeline(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $response = $this->get("/track/{$shipment->tracking_number}");

        $response->assertInertia(fn ($page) => $page->has('shipment.events', 2));
    }

    private function bookedShipmentWithEvent(): Shipment
    {
        $company = $this->createCompany('PTA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);

        $this->actingAs($actor)->post('/shipments', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'declared_value' => 5000,
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One', 'phone' => '+971500000001', 'email' => 'consignor@example.test'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'phone' => '+639170000002', 'email' => 'consignee@example.test', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $shipment = Shipment::query()->latest('id')->firstOrFail();
        $context->forget();

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'booked']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", [
            'status' => 'received',
            'location' => 'Dubai hub',
        ]);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", [
            'status' => 'in_transit',
            'description' => 'Internal warehouse note: check customs paperwork.',
            'is_public' => false,
        ]);

        return $shipment;
    }
}
