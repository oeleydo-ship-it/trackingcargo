<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\UserStatus;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Lifecycle for platform-admin accounts: users with is_platform_admin=true
 * and no company_id of their own. Deliberately not
 * Identity\UserInvitationService — that one requires
 * TenantContext::requireCompanyId() and compares company_id between actor
 * and target, neither of which makes sense for an account that belongs to no
 * company. The invite-accept flow (InvitationController, the signed
 * "invitation.accept" route, UserInvitationService::accept()) is shared as-is
 * since it only ever looks at the target user's own status.
 */
final readonly class PlatformAdminService
{
    public function __construct(private AuditService $audit) {}

    public function invite(array $data, User $actor): User
    {
        $user = DB::transaction(function () use ($data, $actor): User {
            $user = new User;
            $user->forceFill([
                'company_id' => null,
                'branch_id' => null,
                'is_platform_admin' => true,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => Arr::get($data, 'phone'),
                'password' => Hash::make(Str::random(40)),
                'status' => UserStatus::Invited,
            ]);
            $user->save();

            $this->audit->record('platform-admin.invited', $actor, $user, newValues: $user->only(['name', 'email', 'phone']));

            return $user;
        });

        $user->notify(new UserInvited($this->acceptUrl($user), PlatformSetting::current()->site_name));

        return $user;
    }

    public function suspend(User $target, User $actor): void
    {
        $this->assertManageable($target, $actor);

        DB::transaction(function () use ($target, $actor): void {
            $oldStatus = $target->status;
            $target->forceFill(['status' => UserStatus::Suspended])->save();
            app(UserAccessService::class)->revoke($target);
            $this->audit->record('platform-admin.suspended', $actor, $target, oldValues: ['status' => $oldStatus->value], newValues: ['status' => UserStatus::Suspended->value]);
        });
    }

    public function reactivate(User $target, User $actor): void
    {
        $this->assertManageable($target, $actor);

        DB::transaction(function () use ($target, $actor): void {
            $oldStatus = $target->status;
            $target->forceFill(['status' => UserStatus::Active])->save();
            $this->audit->record('platform-admin.reactivated', $actor, $target, oldValues: ['status' => $oldStatus->value], newValues: ['status' => UserStatus::Active->value]);
        });
    }

    private function assertManageable(User $target, User $actor): void
    {
        if (! $target->is_platform_admin) {
            throw new AuthorizationException('That account is not a platform admin.');
        }

        if ($target->getKey() === $actor->getKey()) {
            throw new AuthorizationException('You cannot change your own account this way.');
        }
    }

    private function acceptUrl(User $user): string
    {
        return URL::temporarySignedRoute('invitation.accept', now()->addDays(7), ['user' => $user->getKey()]);
    }
}
