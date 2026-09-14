<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Settings -> Platform admins: the roster of accounts with is_platform_admin
 * = true and no company of their own. Separate from a company's own Users
 * list (Settings\UserController) — see PlatformAdminService's docblock for
 * why Identity\UserInvitationService isn't reused here.
 */
final class PlatformAdminManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_company_scoped_user_cannot_view_or_manage_platform_admins(): void
    {
        $company = $this->createCompany('PMA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['*']);

        $this->actingAs($actor)->get('/settings/platform/admins')->assertForbidden();
        $this->actingAs($actor)->post('/settings/platform/admins', ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
    }

    public function test_a_platform_admin_can_invite_another_platform_admin(): void
    {
        Notification::fake();
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post('/settings/platform/admins', [
            'name' => 'Second Admin',
            'email' => 'second-admin@example.test',
            'phone' => '555-0100',
        ])->assertRedirect();

        $invited = User::query()->where('email', 'second-admin@example.test')->firstOrFail();
        self::assertTrue($invited->is_platform_admin);
        self::assertNull($invited->company_id);
        self::assertSame(UserStatus::Invited, $invited->status);

        Notification::assertSentTo($invited, UserInvited::class);

        $this->actingAs($admin)->get('/settings/platform/admins')->assertInertia(fn ($page) => $page
            ->has('admins', 2));
    }

    public function test_a_platform_admin_can_suspend_and_reactivate_another_platform_admin(): void
    {
        $admin = $this->platformAdmin();
        $target = $this->platformAdmin();

        $this->actingAs($admin)->post("/settings/platform/admins/{$target->getKey()}/suspend")->assertRedirect();
        self::assertSame(UserStatus::Suspended, $target->refresh()->status);

        $this->actingAs($admin)->post("/settings/platform/admins/{$target->getKey()}/reactivate")->assertRedirect();
        self::assertSame(UserStatus::Active, $target->refresh()->status);
    }

    public function test_a_platform_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post("/settings/platform/admins/{$admin->getKey()}/suspend")->assertForbidden();
    }

    public function test_a_platform_admin_cannot_suspend_an_ordinary_company_user_through_this_endpoint(): void
    {
        $admin = $this->platformAdmin();
        $company = $this->createCompany('PMB');
        $branch = $this->createBranch($company, 'DXB');
        $target = $this->createUser($company, $branch);

        $this->actingAs($admin)->post("/settings/platform/admins/{$target->getKey()}/suspend")->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-mgmt-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }
}
