<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

final class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicles.view');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->company_id === $vehicle->company_id
            && ($user->branch_id === null || $vehicle->branch_id === null || $user->branch_id === $vehicle->branch_id || $user->hasPermission('vehicles.manage'))
            && $user->hasPermission('vehicles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicles.manage');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->company_id === $vehicle->company_id && $user->hasPermission('vehicles.manage');
    }
}
