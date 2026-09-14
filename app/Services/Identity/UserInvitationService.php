<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Services\Audit\AuditService;
use App\Services\Platform\UserAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UserInvitationService
{
    public function __construct(private TenantContext $tenantContext, private AuditService $audit) {}

    public function invite(array $data, User $actor): User
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $branchId = Arr::get($data, 'branch_id');

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'The selected branch is not available to this company.']);
        }

        $user = DB::transaction(function () use ($data, $actor, $companyId, $branchId): User {
            $user = new User;
            $user->forceFill([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => Arr::get($data, 'phone'),
                'password' => Hash::make(Str::random(40)),
                'status' => UserStatus::Invited,
            ]);
            $user->save();

            $this->audit->record('user.invited', $actor, $user, newValues: $user->only(['company_id', 'branch_id', 'name', 'email', 'phone']));

            return $user;
        });

        $user->notify(new UserInvited($this->acceptUrl($user), (string) $user->company?->name));

        return $user;
    }

    public function accept(User $user, string $password): void
    {
        if ($user->status !== UserStatus::Invited) {
            throw ValidationException::withMessages(['email' => 'This invitation has already been used or is no longer valid.']);
        }

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $this->audit->record('user.invitation-accepted', $user, $user);
        });
    }

    public function suspend(User $target, User $actor): void
    {
        $this->assertSameCompany($target, $actor);

        if ($target->getKey() === $actor->getKey()) {
            throw new AuthorizationException('You cannot suspend your own account.');
        }

        DB::transaction(function () use ($target, $actor): void {
            $oldStatus = $target->status;
            $target->forceFill(['status' => UserStatus::Suspended])->save();
            app(UserAccessService::class)->revoke($target);
            $this->audit->record('user.suspended', $actor, $target, oldValues: ['status' => $oldStatus->value], newValues: ['status' => UserStatus::Suspended->value]);
        });
    }

    public function reactivate(User $target, User $actor): void
    {
        $this->assertSameCompany($target, $actor);

        DB::transaction(function () use ($target, $actor): void {
            $oldStatus = $target->status;
            $target->forceFill(['status' => UserStatus::Active])->save();
            $this->audit->record('user.reactivated', $actor, $target, oldValues: ['status' => $oldStatus->value], newValues: ['status' => UserStatus::Active->value]);
        });
    }

    private function assertSameCompany(User $target, User $actor): void
    {
        $companyId = $actor->is_platform_admin ? $this->tenantContext->companyId() : $actor->company_id;
        if ($target->is_platform_admin || $companyId === null || ! $actor->hasPermission('users.manage') || (int) $target->company_id !== (int) $companyId) {
            throw new AuthorizationException('You cannot manage this user.');
        }
    }

    private function acceptUrl(User $user): string
    {
        return URL::temporarySignedRoute('invitation.accept', now()->addDays(7), ['user' => $user->getKey()]);
    }
}
