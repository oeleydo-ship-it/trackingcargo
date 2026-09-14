<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Driver;
use Illuminate\Support\Collection;

/**
 * Picks a driver from a pre-filtered candidate list (company/branch/zone
 * filtering happens in DeliveryAssignmentService, not here — a strategy only
 * decides *which* of the eligible drivers is best). Bound to a concrete
 * implementation in AppServiceProvider; swap the binding to change dispatch
 * behavior without touching DeliveryAssignmentService.
 */
interface AssignmentStrategy
{
    /** @param Collection<int, Driver> $candidates */
    public function selectDriver(Collection $candidates): ?Driver;
}
