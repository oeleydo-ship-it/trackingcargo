<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryZone;
use App\Models\User;

final class DeliveryZonePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('deliveries.view');
    }

    public function view(User $user, DeliveryZone $zone): bool
    {
        return $user->company_id === $zone->company_id
            && ($user->branch_id === null || $zone->branch_id === null || $user->branch_id === $zone->branch_id || $user->hasPermission('deliveries.manage'))
            && $user->hasPermission('deliveries.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('deliveries.manage');
    }
}
