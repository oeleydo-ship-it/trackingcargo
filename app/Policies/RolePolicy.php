<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->company_id === $role->company_id && $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->company_id === $role->company_id && $user->hasPermission('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system && $this->update($user, $role);
    }
}
