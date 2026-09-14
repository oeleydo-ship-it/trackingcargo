<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomsClearance;
use App\Models\Shipment;
use App\Models\User;

final class CustomsClearancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customs.view');
    }

    public function view(User $user, CustomsClearance $clearance): bool
    {
        return $user->company_id === $clearance->company_id
            && ($user->branch_id === null || $clearance->branch_id === null || $user->branch_id === $clearance->branch_id || $user->hasPermission('customs.manage'))
            && $user->hasPermission('customs.view');
    }

    /**
     * A not-yet-created clearance has no model to authorize against, so the
     * target shipment is passed as a second argument, the same shape as
     * MasterPolicy::create()'s mode argument.
     */
    public function create(User $user, ?Shipment $shipment = null): bool
    {
        if (! $user->hasPermission('customs.manage')) {
            return false;
        }

        if ($shipment === null) {
            return true;
        }

        return $user->company_id === $shipment->company_id
            && ($user->branch_id === null || $user->branch_id === $shipment->branch_id);
    }

    public function update(User $user, CustomsClearance $clearance): bool
    {
        return $user->company_id === $clearance->company_id && $user->hasPermission('customs.manage');
    }

    public function transition(User $user, CustomsClearance $clearance): bool
    {
        return $this->update($user, $clearance);
    }
}
