<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use App\Models\Company;
use App\Models\TrackingEvent;
use App\Services\Tracking\CarrierProviderRegistry;
use App\Services\Tracking\CarrierTrackingPollService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The mock provider only progresses `received -> in_transit -> at_customs`
 * — never past customs, since `delivered` stays exclusively POD-gated
 * (Phase 7) and clearance stays exclusively owned by CustomsClearance
 * (Phase 6). "30+ minutes since last_status_at" is simulated by backdating
 * the column directly rather than sleeping in the test.
 */
final class CarrierTrackingPollServiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_registry_resolves_the_seeded_mock_provider(): void
    {
        self::assertContains('mock', app(CarrierProviderRegistry::class)->codes());
        self::assertSame('mock', app(CarrierProviderRegistry::class)->resolve('mock')->code());
    }

    public function test_a_shipment_with_no_carrier_is_left_untouched(): void
    {
        $company = $this->createCompany('CTA');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['status' => 'received', 'last_status_at' => now()->subHour()]);

        app(CarrierTrackingPollService::class)->pollAll();

        $this->withTenant($company, function () use ($shipment): void {
            self::assertSame('received', $shipment->fresh()->status);
        });
    }

    public function test_a_shipment_polled_too_recently_is_not_advanced(): void
    {
        $company = $this->createCompany('CTB');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['status' => 'received', 'carrier_code' => 'mock', 'last_status_at' => now()]);

        app(CarrierTrackingPollService::class)->pollAll();

        $this->withTenant($company, function () use ($shipment): void {
            self::assertSame('received', $shipment->fresh()->status);
        });
    }

    public function test_a_stale_received_shipment_advances_to_in_transit_and_writes_a_system_attributed_event(): void
    {
        $company = $this->createCompany('CTC');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['status' => 'received', 'carrier_code' => 'mock', 'last_status_at' => now()->subMinutes(45)]);

        app(CarrierTrackingPollService::class)->pollAll();

        $this->withTenant($company, function () use ($shipment): void {
            $shipment->refresh();
            self::assertSame('in_transit', $shipment->status);

            $event = TrackingEvent::query()->where('shipment_id', $shipment->getKey())->latest('id')->firstOrFail();
            self::assertNull($event->created_by);
            self::assertSame('in_transit', $event->to_status);
        });
    }

    public function test_polling_never_advances_a_shipment_past_at_customs(): void
    {
        $company = $this->createCompany('CTD');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['status' => 'at_customs', 'carrier_code' => 'mock', 'last_status_at' => now()->subMinutes(45)]);

        app(CarrierTrackingPollService::class)->pollAll();

        $this->withTenant($company, function () use ($shipment): void {
            self::assertSame('at_customs', $shipment->fresh()->status);
        });
    }

    public function test_polling_a_terminal_shipment_is_skipped_entirely(): void
    {
        $company = $this->createCompany('CTE');
        $branch = $this->createBranch($company, 'DXB');
        $this->createShipment($company, $branch, [
            'status' => 'delivered',
            'carrier_code' => 'mock',
            'last_status_at' => now()->subDay(),
        ]);

        // Would throw if the query included terminal statuses and the mock
        // provider's match() hit an unmapped case — asserting no exception
        // is itself the proof here.
        app(CarrierTrackingPollService::class)->pollAll();

        self::assertTrue(true);
    }

    private function withTenant(Company $company, Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
    }
}
