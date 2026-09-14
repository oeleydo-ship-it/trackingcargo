<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->company_id === $customer->company_id
            && ($user->branch_id === null || $customer->branch_id === null || $user->branch_id === $customer->branch_id || $user->hasPermission('customers.manage'))
            && $user->hasPermission('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->company_id === $customer->company_id && $user->hasPermission('customers.manage');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }
}
