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
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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

    /**
     * Creates a user who can sign in straight away with a password the
     * administrator chose, instead of emailing an invitation.
     *
     * For companies without mail set up, or staff who need access right now.
     * The email address is taken as verified because an administrator of the
     * company entered it — the same trust an accepted invitation gets — and
     * no email is sent.
     */
    public function createWithPassword(array $data, User $actor): User
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $branchId = Arr::get($data, 'branch_id');

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'The selected branch is not available to this company.']);
        }

        return DB::transaction(function () use ($data, $actor, $companyId, $branchId): User {
            $user = new User;
            $user->forceFill([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => Arr::get($data, 'phone'),
                'password' => Hash::make($data['password']),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);
            $user->save();

            // The password itself is never written to the audit log.
            $this->audit->record('user.created', $actor, $user, newValues: $user->only(['company_id', 'branch_id', 'name', 'email', 'phone']));

            return $user;
        });
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

    /**
     * Gives a user a password their administrator chose, for the day someone
     * is locked out and needs to be working again now: no mail server, no
     * link to wait for. Every existing session and token is dropped, so a
     * password handed over in person is the only way back in — and a stolen
     * session cannot outlive the reset that was meant to end it.
     */
    public function setPassword(User $target, User $actor, string $password): void
    {
        $this->assertSameCompany($target, $actor);

        if ($target->getKey() === $actor->getKey()) {
            // Resetting your own password here would sign you straight out.
            throw new AuthorizationException('Change your own password from My account.');
        }

        DB::transaction(function () use ($target, $actor, $password): void {
            $oldStatus = $target->status;
            $attributes = ['password' => Hash::make($password)];

            // Someone who never accepted their invitation now has a password,
            // which is all that invitation was going to give them.
            if ($oldStatus === UserStatus::Invited) {
                $attributes['status'] = UserStatus::Active;
                $attributes['email_verified_at'] = $target->email_verified_at ?? now();
            }

            $target->forceFill($attributes)->save();

            // A suspended account keeps its status: a password is not access.
            app(UserAccessService::class)->revoke($target);

            // The password itself is never written to the audit log.
            $this->audit->record('user.password-set', $actor, $target, oldValues: ['status' => $oldStatus->value], newValues: ['status' => $target->status->value]);
        });
    }

    /**
     * The other half of a reset: mail the standard reset link so the user
     * picks their own password and the administrator never sees it.
     */
    public function sendPasswordResetLink(User $target, User $actor): void
    {
        $this->assertSameCompany($target, $actor);

        if ($target->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'method' => 'Only an active account can be sent a reset link. Set a password for them instead.',
            ]);
        }

        try {
            $status = Password::sendResetLink(['email' => $target->email]);
        } catch (TransportExceptionInterface) {
            throw ValidationException::withMessages([
                'method' => 'The email could not be sent. Check the SMTP settings, or set a password for them instead.',
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['method' => trans($status)]);
        }

        $this->audit->record('user.password-reset-link-sent', $actor, $target);
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
