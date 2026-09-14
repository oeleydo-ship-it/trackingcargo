<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryAssignment;
use App\Models\Shipment;
use App\Models\User;

final class DeliveryAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('deliveries.view');
    }

    public function view(User $user, DeliveryAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id
            && ($user->branch_id === null || $assignment->branch_id === null || $user->branch_id === $assignment->branch_id || $user->hasPermission('deliveries.manage') || $this->isAssignedDriver($user, $assignment))
            && $user->hasPermission('deliveries.view');
    }

    /**
     * A not-yet-created assignment has no model to authorize against, so the
     * target shipment is passed as a second argument, the same shape as
     * CustomsClearancePolicy::create() and MasterPolicy::create() before it.
     */
    public function create(User $user, ?Shipment $shipment = null): bool
    {
        if (! $user->hasPermission('deliveries.manage')) {
            return false;
        }

        if ($shipment === null) {
            return true;
        }

        return $user->company_id === $shipment->company_id
            && ($user->branch_id === null || $user->branch_id === $shipment->branch_id);
    }

    public function update(User $user, DeliveryAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id && $user->hasPermission('deliveries.manage');
    }

    /**
     * "Only assigned/authorized actors mutate deliveries": a dispatcher with
     * deliveries.manage can always act (correct mistakes, cover for a
     * driver); a user holding only deliveries.execute may act only on an
     * assignment where they are specifically the assigned driver.
     */
    public function execute(User $user, DeliveryAssignment $assignment): bool
    {
        if ($user->company_id !== $assignment->company_id) {
            return false;
        }

        return $user->hasPermission('deliveries.manage') || ($user->hasPermission('deliveries.execute') && $this->isAssignedDriver($user, $assignment));
    }

    private function isAssignedDriver(User $user, DeliveryAssignment $assignment): bool
    {
        return $user->driver !== null && $user->driver->getKey() === $assignment->driver_id;
    }
}
