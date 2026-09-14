<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Contracts\AssignmentStrategy;
use App\Enums\DeliveryAssignmentStatus;
use App\Models\Driver;
use Illuminate\Support\Collection;

/**
 * Picks the candidate with the fewest currently-active (assigned or
 * out-for-delivery) deliveries, ties broken by driver id — a deliberately
 * simple, deterministic default that keeps load roughly even without any
 * geo/routing logic.
 */
final readonly class RoundRobinAssignmentStrategy implements AssignmentStrategy
{
    public function selectDriver(Collection $candidates): ?Driver
    {
        return $candidates
            ->sortBy(fn (Driver $driver): array => [$this->activeLoad($driver), $driver->getKey()])
            ->first();
    }

    private function activeLoad(Driver $driver): int
    {
        return $driver->assignments()
            ->whereIn('status', [DeliveryAssignmentStatus::Assigned->value, DeliveryAssignmentStatus::OutForDelivery->value])
            ->count();
    }
}
