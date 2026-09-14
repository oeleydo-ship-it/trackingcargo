<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

final class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->company_id === $branch->company_id
            && ($user->branch_id === null || $user->branch_id === $branch->getKey() || $user->hasPermission('branches.manage'))
            && $user->hasPermission('branches.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('branches.manage');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->company_id === $branch->company_id && $user->hasPermission('branches.manage');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch) && $branch->users()->doesntExist();
    }
}
