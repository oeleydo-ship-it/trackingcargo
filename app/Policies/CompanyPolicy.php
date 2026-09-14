<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->is_platform_admin || ($user->company_id === $company->getKey() && $user->hasPermission('companies.view'));
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->is_platform_admin || ($user->company_id === $company->getKey() && $user->hasPermission('companies.manage'));
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->is_platform_admin && $company->users()->doesntExist();
    }
}
