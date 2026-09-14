<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RoleAssignmentService
{
    public function __construct(private TenantContext $tenantContext, private AuditService $audit) {}

    public function assign(User $target, Role $role, User $actor): void
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $this->assertAssignmentIsAllowed($target, $role, $actor, $companyId);

        DB::transaction(function () use ($target, $role, $actor, $companyId): void {
            User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $target->roles()->syncWithoutDetaching([$role->getKey() => ['company_id' => $companyId, 'assigned_by' => $actor->getKey()]]);
            $this->audit->record('role.assigned', $actor, $target, newValues: ['role_id' => $role->getKey(), 'role' => $role->slug]);
        });
    }

    public function revoke(User $target, Role $role, User $actor): void
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $this->assertAssignmentIsAllowed($target, $role, $actor, $companyId);

        DB::transaction(function () use ($target, $role, $actor): void {
            $target->roles()->detach($role->getKey());
            $this->audit->record('role.revoked', $actor, $target, oldValues: ['role_id' => $role->getKey(), 'role' => $role->slug]);
        });
    }

    private function assertAssignmentIsAllowed(User $target, Role $role, User $actor, int $companyId): void
    {
        if (! $actor->hasPermission('roles.manage')) {
            throw new AuthorizationException('You cannot manage roles.');
        }

        if ((int) $target->company_id !== $companyId || (int) $role->company_id !== $companyId) {
            throw new AuthorizationException('Cross-company role assignment is forbidden.');
        }

        if ($role->permissions()->where('platform_only', true)->exists()) {
            throw new AuthorizationException('Company roles cannot contain platform permissions.');
        }
    }
}
