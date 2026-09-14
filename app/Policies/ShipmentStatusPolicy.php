<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShipmentStatus;
use App\Models\User;

/**
 * Statuses get their own permission rather than riding on shipments.manage:
 * a booking clerk moves shipments through the workflow every day but should
 * not be able to redefine it for the whole company.
 */
final class ShipmentStatusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('statuses.view') || $user->hasPermission('statuses.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('statuses.manage');
    }

    public function update(User $user, ShipmentStatus $status): bool
    {
        return $user->company_id === $status->company_id && $user->hasPermission('statuses.manage');
    }

    public function delete(User $user, ShipmentStatus $status): bool
    {
        return $this->update($user, $status);
    }
}
