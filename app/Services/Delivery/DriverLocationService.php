<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Models\DriverLocation;

/**
 * A single row per driver holds only the latest known position — this is
 * "live location" (what the dispatch board shows right now), not a
 * breadcrumb trail. Naturally idempotent (an overwrite with the same or a
 * later position is harmless), so no idempotency_key is needed here, unlike
 * DeliveryAttemptService; rate limiting is handled at the route via
 * `throttle:`, matching every other throttled route in this codebase.
 */
final readonly class DriverLocationService
{
    public function ping(Driver $driver, float $latitude, float $longitude): DriverLocation
    {
        $location = DriverLocation::query()->updateOrCreate(
            ['driver_id' => $driver->getKey()],
            ['latitude' => $latitude, 'longitude' => $longitude, 'recorded_at' => now()],
        );

        event(new DriverLocationUpdated($location));

        return $location;
    }
}
