<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\Permission;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Providers\AppServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class SuperadminConsoleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function admin(): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Platform admin', 'email' => 'root@example.test', 'password' => bcrypt('secret-password'), 'status' => 'active', 'is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user;
    }

    public function test_company_administrators_cannot_use_any_superadmin_control(): void
    {
        $company = $this->createCompany('SAA');
        $user = $this->createUser($company);
        $this->grantPermissions($user, ['users.manage', 'company.manage']);
        $this->actingAs($user)->get('/superadmin')->assertForbidden();
        $this->get('/superadmin/users')->assertForbidden();
        $this->get('/superadmin/users/'.$user->id)->assertForbidden();
        $this->post('/superadmin/workspaces', [])->assertForbidden();
        $this->patch('/superadmin/workspaces/'.$company->id, [])->assertForbidden();
        $this->patch('/superadmin/users/'.$user->id, [])->assertForbidden();
        $this->post('/superadmin/users/'.$user->id.'/suspend', ['reason' => 'test'])->assertForbidden();
        $this->post('/settings/platform/stripe/test')->assertForbidden();
    }

    public function test_workspace_provisioning_invites_a_company_admin_without_platform_privileges(): void
    {
        Notification::fake();
        Permission::query()->create(['name' => 'View shipments', 'slug' => 'shipments.view', 'group' => 'shipments', 'platform_only' => false]);
        Permission::query()->create(['name' => 'Platform', 'slug' => 'system-configuration.manage', 'group' => 'platform', 'platform_only' => true]);
        $this->actingAs($this->admin())->post('/superadmin/workspaces', [
            'name' => 'New workspace', 'code' => 'NEW', 'slug' => 'new-workspace', 'country_code' => 'AE', 'timezone' => 'Asia/Dubai', 'default_currency' => 'AED',
            'branch_name' => 'Dubai', 'branch_code' => 'DXB', 'city' => 'Dubai', 'admin_name' => 'Owner', 'admin_email' => 'owner@example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $company = Company::query()->where('code', 'NEW')->firstOrFail();
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        self::assertSame($company->id, $owner->company_id);
        self::assertFalse($owner->is_platform_admin);
        self::assertSame('invited', $owner->status->value);
        Notification::assertSentTo($owner, UserInvited::class);
        app(TenantContext::class)->resolveCompany((int) $company->id);
        self::assertTrue($owner->hasPermission('shipments.view'));
        self::assertFalse($owner->hasPermission('system-configuration.manage'));
        self::assertSame(1, $company->branches()->count());
        self::assertGreaterThan(0, $company->shipmentStatuses()->count());
        app(TenantContext::class)->forget();
    }

    public function test_workspace_suspension_revokes_only_its_users_access_and_preserves_records(): void
    {
        config(['session.driver' => 'database']);
        $a = $this->createCompany('SBA');
        $b = $this->createCompany('SBB');
        $userA = $this->createUser($a);
        $userB = $this->createUser($b);
        $tokenA = $userA->createToken('a');
        $tokenB = $userB->createToken('b');
        DB::table('sessions')->insert(['id' => 'user-a-session', 'user_id' => $userA->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->admin())->patch('/superadmin/workspaces/'.$a->id, ['name' => $a->name, 'status' => 'suspended', 'reason' => 'Requested pause'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenA->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenB->accessToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-a-session']);
        $this->assertDatabaseHas('users', ['id' => $userA->id, 'deleted_at' => null]);
        $this->actingAs($userA)->get('/dashboard')->assertForbidden();
    }

    public function test_global_directory_works_while_acting_as_a_company_and_never_returns_secrets(): void
    {
        $a = $this->createCompany('SCA');
        $b = $this->createCompany('SCB');
        $this->createUser($a);
        $this->createUser($b);
        $this->actingAs($this->admin())->withSession(['platform_acting_company_id' => $a->id]);
        $this->get('/superadmin')->assertOk()->assertInertia(fn ($page) => $page->has('workspaces.data', 2));
        $this->get('/superadmin/users')->assertOk()->assertInertia(fn ($page) => $page->has('users.data', 2)->missing('users.data.0.password')->missing('users.data.0.two_factor_secret'));
        $this->get('/superadmin/users?company_id='.$b->id)->assertOk()->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.company_id', $b->id));
    }

    public function test_user_controls_validate_company_roles_and_branches_and_revoke_access(): void
    {
        $a = $this->createCompany('SDA');
        $b = $this->createCompany('SDB');
        $branchA = $this->createBranch($a, 'DXB');
        $branchB = $this->createBranch($b, 'MNL');
        $user = $this->createUser($a);
        $this->grantPermissions($user, ['shipments.view']);
        $otherUser = $this->createUser($b);
        $this->grantPermissions($otherUser, ['shipments.manage']);
        $roleB = Role::withoutGlobalScopes()->where('company_id', $b->id)->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/superadmin/users/'.$user->id)->assertOk();
        $this->patch('/superadmin/users/'.$user->id, ['name' => 'Edited', 'branch_id' => $branchB->id, 'roles' => [$roleB->id], 'reason' => 'Invalid move'])->assertSessionHasErrors(['branch_id', 'roles.0']);
        $token = $user->createToken('old');
        $this->patch('/superadmin/users/'.$user->id, ['name' => 'Edited', 'branch_id' => $branchA->id, 'roles' => [], 'reason' => 'Access review', 'company_id' => $b->id, 'is_platform_admin' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'company_id' => $a->id, 'branch_id' => $branchA->id, 'is_platform_admin' => false]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->post('/superadmin/users/'.$admin->id.'/suspend', ['reason' => 'Self'])->assertNotFound();
        $this->post('/superadmin/users/'.$user->id.'/suspend', ['reason' => 'Review'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended']);
    }

    public function test_invited_accounts_cannot_be_activated_without_acceptance(): void
    {
        Notification::fake();
        $user = $this->createUser($this->createCompany('SEA'));
        $user->forceFill(['status' => 'invited', 'email_verified_at' => null])->save();
        $this->actingAs($this->admin())->post('/superadmin/users/'.$user->id.'/activate', ['reason' => 'test'])->assertSessionHasErrors('action');
        $this->post('/superadmin/users/'.$user->id.'/invite', ['reason' => 'Resend requested'])->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentTo($user, UserInvited::class);
    }

    public function test_gateway_connection_check_is_read_only_and_secrets_are_hidden(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/balance' => Http::response(['object' => 'balance', 'livemode' => false])]);
        $settings = PlatformSetting::current();
        $settings->fill(['stripe_secret_key' => 'sk_test_hidden', 'smtp_password' => 'mail-hidden'])->save();
        self::assertArrayNotHasKey('stripe_secret_key', $settings->toArray());
        self::assertArrayNotHasKey('smtp_password', $settings->toArray());
        $this->actingAs($this->admin())->post('/settings/platform/stripe/test')->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.stripe.com/v1/balance');
        Http::assertSentCount(1);
    }

    public function test_smtp_modes_build_valid_transports_without_connecting(): void
    {
        $settings = PlatformSetting::current();
        foreach (['tls' => 'smtp', 'ssl' => 'smtps'] as $encryption => $scheme) {
            $settings->fill(['smtp_host' => 'mail.example.test', 'smtp_port' => 587, 'smtp_encryption' => $encryption])->save();
            app()->getProvider(AppServiceProvider::class)->boot();
            self::assertSame($scheme, config('mail.mailers.smtp.scheme'));
            Mail::purge('smtp');
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            self::assertSame($encryption === 'tls', $transport->isTlsRequired());
        }
    }

    public function test_gateway_validation_rejects_mixed_modes_without_flashing_secrets(): void
    {
        $this->actingAs($this->admin())->patch('/settings/platform/stripe', ['stripe_publishable_key' => 'pk_live_example', 'stripe_secret_key' => 'sk_test_example', 'stripe_webhook_secret' => 'whsec_example'])
            ->assertSessionHasErrors('stripe_publishable_key')
            ->assertSessionMissing('_old_input.stripe_secret_key')->assertSessionMissing('_old_input.stripe_webhook_secret');
        self::assertNull(PlatformSetting::current()->stripe_secret_key);
    }
}
