<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\CarrierTrackingUpdate;
use App\Models\Shipment;

/**
 * One named carrier integration. Resolved by code from CarrierProviderRegistry
 * (config/carriers.php maps code => implementation), never instantiated
 * directly by callers — the same "interface + swappable binding" shape as
 * AssignmentStrategy, but a registry of many named providers rather than one
 * bound default, since a company can use more than one carrier at once.
 */
interface CarrierProviderInterface
{
    public function code(): string;

    /**
     * Return null when there is nothing new to report — CarrierTrackingPollService
     * then leaves the shipment untouched rather than forcing a no-op transition.
     */
    public function poll(Shipment $shipment): ?CarrierTrackingUpdate;
}
