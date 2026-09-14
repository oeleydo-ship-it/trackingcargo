<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

final class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('drivers.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->company_id === $driver->company_id
            && ($user->branch_id === null || $driver->branch_id === null || $user->branch_id === $driver->branch_id || $user->hasPermission('drivers.manage'))
            && $user->hasPermission('drivers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('drivers.manage');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->company_id === $driver->company_id && $user->hasPermission('drivers.manage');
    }
}
