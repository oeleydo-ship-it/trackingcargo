<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('audit-logs.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->company_id === $auditLog->company_id && $user->hasPermission('audit-logs.view');
    }
}
