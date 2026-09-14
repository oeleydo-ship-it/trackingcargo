<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RateCard;
use App\Models\User;

final class RateCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('rates.view');
    }

    public function view(User $user, RateCard $rateCard): bool
    {
        return $user->company_id === $rateCard->company_id
            && ($user->branch_id === null || $rateCard->branch_id === null || $user->branch_id === $rateCard->branch_id || $user->hasPermission('rates.manage'))
            && $user->hasPermission('rates.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('rates.manage');
    }

    public function update(User $user, RateCard $rateCard): bool
    {
        return $user->company_id === $rateCard->company_id && $user->hasPermission('rates.manage');
    }
}
