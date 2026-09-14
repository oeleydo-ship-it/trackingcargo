<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

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

        // The consignor/consignee names, declared value, and any internal (non-public)
        // tracking notes must never reach an anonymous visitor.
        self::assertStringNotContainsString('Consignor One', $shipmentData);
        self::assertStringNotContainsString('Consignee One', $shipmentData);
        self::assertStringNotContainsString('Internal warehouse note', $shipmentData);
        self::assertArrayNotHasKey('declared_value', $page['shipment']);
        self::assertArrayNotHasKey('company_id', $page['shipment']);
        self::assertArrayNotHasKey('customer_id', $page['shipment']);
    }

    public function test_the_public_page_shows_a_masked_sender_and_receiver_with_only_city_and_country(): void
    {
        $shipment = $this->bookedShipmentWithEvent();

        $response = $this->get("/track/{$shipment->tracking_number}");

        $response->assertInertia(fn ($page) => $page
            ->where('shipment.sender.name', 'Consignor O.')
            ->where('shipment.sender.city', null)
            ->where('shipment.receiver.name', 'Consignee O.')
            ->where('shipment.receiver.city', 'Manila')
            ->where('shipment.receiver.country_code', 'PH'));

        // The full given name and the exact street address must still never
        // reach the response, even though "who" and "roughly where" now do.
        $page = $response->viewData('page')['props'];
        $shipmentData = json_encode($page['shipment']);
        self::assertStringNotContainsString('Consignor One', $shipmentData);
        self::assertStringNotContainsString('Consignee One', $shipmentData);
        self::assertStringNotContainsString('24 Mabini Street', $shipmentData);
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
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
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
