<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Contracts\CarrierProviderInterface;
use App\DataTransferObjects\CarrierTrackingUpdate;
use App\Enums\ShipmentStatusRole;
use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * Reference/demo implementation of CarrierProviderInterface — there is no
 * real third-party carrier API to integrate against in this environment, so
 * this simulates a plausible feed instead of faking a real one. Deliberately
 * restricted to pre-customs progression only (received -> in_transit ->
 * at_customs): `delivered` stays exclusively POD-gated by
 * DeliveryAttemptService (Phase 7's structural guarantee) and customs
 * clearance stays exclusively owned by CustomsClearance (Phase 6) — an
 * automated carrier feed must never shortcut either state machine, so it
 * never reports a mapped status past `at_customs`.
 */
final readonly class MockCarrierProvider implements CarrierProviderInterface
{
    private const int ADVANCE_AFTER_MINUTES = 30;

    public function code(): string
    {
        return 'mock';
    }

    public function poll(Shipment $shipment): ?CarrierTrackingUpdate
    {
        $sinceLastStatus = $shipment->last_status_at !== null
            ? abs(CarbonImmutable::now()->diffInMinutes($shipment->last_status_at))
            : null;

        if ($sinceLastStatus === null || $sinceLastStatus < self::ADVANCE_AFTER_MINUTES) {
            return null;
        }

        $mappedStatus = match ($shipment->shipmentStatus?->role) {
            ShipmentStatusRole::Received => ShipmentStatusRole::InTransit,
            ShipmentStatusRole::InTransit => ShipmentStatusRole::AtCustoms,
            default => null,
        };

        if ($mappedStatus === null) {
            return null;
        }

        return new CarrierTrackingUpdate(
            externalStatus: $mappedStatus->value,
            mappedStatus: $mappedStatus,
            location: null,
            description: "Carrier feed (mock): {$mappedStatus->label()}.",
            occurredAt: CarbonImmutable::now(),
        );
    }
}
