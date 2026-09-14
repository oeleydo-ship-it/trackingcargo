<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->getKey() === $subject->getKey()
            || ($user->company_id === $subject->company_id && $user->hasPermission('users.view'));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->company_id === $subject->company_id && $user->hasPermission('users.manage');
    }

    public function delete(User $user, User $subject): bool
    {
        return $user->getKey() !== $subject->getKey() && $this->update($user, $subject);
    }
}
