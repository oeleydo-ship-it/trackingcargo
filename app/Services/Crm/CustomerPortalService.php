<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\UserInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerPortalService
{
    public function __construct(
        private TenantContext $tenantContext,
        private UserInvitationService $invitations,
        private AuditService $audit,
    ) {}

    public function invite(Customer $customer, array $data, User $actor): User
    {
        if ($customer->portal_user_id !== null) {
            throw ValidationException::withMessages(['email' => 'This customer already has a portal account.']);
        }

        $companyId = $this->tenantContext->requireCompanyId();

        return DB::transaction(function () use ($customer, $data, $actor, $companyId): User {
            $portalUser = $this->invitations->invite($data, $actor);

            $role = Role::query()->where('slug', 'customer')->firstOrFail();
            $portalUser->roles()->attach($role->getKey(), ['company_id' => $companyId, 'assigned_by' => $actor->getKey()]);

            $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save();

            $this->audit->record('customer.portal-linked', $actor, $customer, newValues: ['portal_user_id' => $portalUser->getKey()]);

            return $portalUser;
        });
    }

    public function unlink(Customer $customer, User $actor): void
    {
        $oldPortalUserId = $customer->portal_user_id;

        $customer->forceFill(['portal_user_id' => null])->save();

        $this->audit->record('customer.portal-unlinked', $actor, $customer, oldValues: ['portal_user_id' => $oldPortalUserId]);
    }
}
