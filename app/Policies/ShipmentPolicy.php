<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

final class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shipments.view');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->company_id !== $shipment->company_id || ! $user->hasPermission('shipments.view')) {
            return false;
        }

        /**
         * A customer portal login (branch_id always null — see
         * User::customerProfile()) must never fall through to the
         * branch_id === null staff-bypass below, or they'd see every other
         * customer's shipments in the company.
         */
        if ($user->customerProfile !== null) {
            return $user->customerProfile->getKey() === $shipment->customer_id;
        }

        return $user->branch_id === null || $user->branch_id === $shipment->branch_id || $user->hasPermission('shipments.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shipments.manage');
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->company_id === $shipment->company_id && $user->hasPermission('shipments.manage');
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->company_id === $shipment->company_id && $user->hasPermission('shipments.manage');
    }

    public function transition(User $user, Shipment $shipment): bool
    {
        return $user->company_id === $shipment->company_id && $user->hasPermission('tracking.update');
    }
}
