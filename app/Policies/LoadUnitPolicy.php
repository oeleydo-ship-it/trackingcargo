<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoadUnit;
use App\Models\User;

final class LoadUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('containers.view');
    }

    public function view(User $user, LoadUnit $unit): bool
    {
        return $user->company_id === $unit->company_id && $user->hasPermission('containers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('containers.manage');
    }

    public function update(User $user, LoadUnit $unit): bool
    {
        return $user->company_id === $unit->company_id && $user->hasPermission('containers.manage');
    }
}
