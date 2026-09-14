<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('company.{companyId}', function (User $user, int $companyId): bool {
    return $user->is_platform_admin || (int) $user->company_id === $companyId;
});

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return (int) $user->getKey() === $userId;
});
