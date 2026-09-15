<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Public, self-service workspace sign-up at /register, and the superadmin's
 * switches that open, close and gate it.
 */
final class WorkspaceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_is_closed_by_default(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->payload())->assertNotFound();

        self::assertSame(0, Company::query()->count());
    }

    public function test_the_login_page_only_offers_sign_up_while_it_is_open(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('registrationEnabled', false));

        $this->openRegistration(requiresApproval: true);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('registrationEnabled', true));
        $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Register')->where('requiresApproval', true));
    }

    public function test_with_approval_on_a_new_workspace_waits_and_cannot_sign_in(): void
    {
        Notification::fake();
        $this->openRegistration(requiresApproval: true);

        $this->post('/register', $this->payload())
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertGuest();

        $company = Company::query()->where('name', 'Acme Freight LLC')->firstOrFail();
        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        self::assertSame(CompanyStatus::Pending, $company->status);
        self::assertSame($company->getKey(), $admin->company_id);
        self::assertSame(UserStatus::Active, $admin->status);
        self::assertFalse($admin->is_platform_admin);
        self::assertNull($admin->email_verified_at);

        // Verification waits for approval: its link only works for an active workspace.
        Notification::assertNothingSentTo($admin);

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'correct-horse-42-battery'])
            ->assertSessionHasErrors(['email' => 'Your workspace is still waiting for approval. You will be able to sign in once it is approved.']);

        $this->assertGuest();
    }

    public function test_a_registered_workspace_is_fully_set_up(): void
    {
        $this->openRegistration(requiresApproval: false);

        $this->post('/register', $this->payload());

        $company = Company::query()->where('name', 'Acme Freight LLC')->firstOrFail();
        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        $this->withTenant($company, function () use ($company, $admin): void {
            $branch = Branch::query()->sole();
            self::assertTrue($branch->is_head_office);
            self::assertSame('Manila', $branch->city);
            self::assertSame('PH', $branch->country_code);

            $role = Role::query()->where('slug', 'workspace-admin')->firstOrFail();
            self::assertTrue($admin->roles()->whereKey($role->getKey())->exists());
            self::assertTrue($admin->hasPermission('shipments.manage'));
            self::assertFalse($admin->hasPermission('system-configuration.manage'), 'A registrant must never receive platform-only permissions.');

            self::assertGreaterThan(0, $company->shipmentStatuses()->count(), 'The default shipment workflow should be provisioned.');
        });
    }

    public function test_approving_a_pending_workspace_emails_a_verification_link_and_allows_sign_in(): void
    {
        Notification::fake();
        $this->openRegistration(requiresApproval: true);
        $this->post('/register', $this->payload());

        $company = Company::query()->where('name', 'Acme Freight LLC')->firstOrFail();
        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->patch("/superadmin/workspaces/{$company->getKey()}", ['name' => $company->name, 'status' => 'active', 'reason' => 'Approved sign-up'])
            ->assertSessionHasNoErrors();

        self::assertSame(CompanyStatus::Active, $company->fresh()->status);
        Notification::assertSentTo($admin, VerifyEmail::class);

        $this->post('/logout');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'correct-horse-42-battery'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_with_approval_off_the_registrant_is_signed_in_and_asked_to_verify(): void
    {
        Notification::fake();
        $this->openRegistration(requiresApproval: false);

        $this->post('/register', $this->payload())->assertRedirect(route('dashboard'));

        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        self::assertSame(CompanyStatus::Active, $admin->company->status);
        $this->assertAuthenticatedAs($admin);
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_with_verification_off_the_registrant_is_verified_and_no_email_is_sent(): void
    {
        Notification::fake();
        $this->openRegistration(requiresApproval: false, requiresEmailVerification: false);

        $this->get('/register')->assertInertia(fn ($page) => $page->where('requiresEmailVerification', false));

        $this->post('/register', $this->payload())->assertRedirect(route('dashboard'));

        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        self::assertNotNull($admin->email_verified_at);
        $this->assertAuthenticatedAs($admin);
        Notification::assertNothingSent();

        // Straight into the app, not stopped at the verify-email page.
        $this->get('/dashboard')->assertOk();
    }

    public function test_approving_with_verification_off_marks_the_admin_verified_without_email(): void
    {
        Notification::fake();
        $this->openRegistration(requiresApproval: true);
        $this->post('/register', $this->payload());

        // Verification switched off after the sign-up but before approval.
        PlatformSetting::current()->forceFill(['registration_requires_email_verification' => false])->save();

        $company = Company::query()->where('name', 'Acme Freight LLC')->firstOrFail();
        $admin = User::query()->where('email', 'owner@acme.test')->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->patch("/superadmin/workspaces/{$company->getKey()}", ['name' => $company->name, 'status' => 'active', 'reason' => 'Approved sign-up'])
            ->assertSessionHasNoErrors();

        self::assertNotNull($admin->fresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_the_verification_switch_is_saved(): void
    {
        $this->actingAs($this->platformAdmin())
            ->patch('/superadmin/registration', ['registration_enabled' => true, 'registration_requires_approval' => true, 'registration_requires_email_verification' => false])
            ->assertSessionHasNoErrors();

        self::assertFalse(PlatformSetting::current()->registration_requires_email_verification);
    }

    public function test_an_email_that_already_has_an_account_is_rejected(): void
    {
        $this->openRegistration(requiresApproval: true);
        $this->post('/register', $this->payload());

        $this->post('/register', $this->payload(['company_name' => 'Someone Else Ltd']))
            ->assertSessionHasErrors('email');

        self::assertSame(1, Company::query()->count());
    }

    public function test_two_companies_with_the_same_name_get_distinct_codes(): void
    {
        $this->openRegistration(requiresApproval: true);

        $this->post('/register', $this->payload());
        $this->post('/register', $this->payload(['email' => 'second@acme.test']));

        $companies = Company::query()->where('name', 'Acme Freight LLC')->get();

        self::assertCount(2, $companies);
        self::assertCount(2, $companies->pluck('code')->unique());
        self::assertCount(2, $companies->pluck('slug')->unique());
    }

    public function test_the_authorisation_confirmation_and_password_policy_are_required(): void
    {
        $this->openRegistration(requiresApproval: true);

        $this->post('/register', $this->payload(['terms' => false]))->assertSessionHasErrors('terms');
        $this->post('/register', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))->assertSessionHasErrors('password');

        self::assertSame(0, Company::query()->count());
    }

    public function test_a_superadmin_can_switch_sign_up_on_and_off(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->patch('/superadmin/registration', ['registration_enabled' => true, 'registration_requires_approval' => false, 'registration_requires_email_verification' => true])
            ->assertSessionHasNoErrors();

        $settings = PlatformSetting::current();
        self::assertTrue($settings->registration_enabled);
        self::assertFalse($settings->registration_requires_approval);

        $this->actingAs($admin)
            ->patch('/superadmin/registration', ['registration_enabled' => false, 'registration_requires_approval' => true, 'registration_requires_email_verification' => true])
            ->assertSessionHasNoErrors();

        self::assertFalse(PlatformSetting::current()->registration_enabled);
    }

    public function test_the_superadmin_console_shows_the_switches_and_pending_count(): void
    {
        $this->openRegistration(requiresApproval: true);
        $this->post('/register', $this->payload());

        $this->actingAs($this->platformAdmin())
            ->get('/superadmin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('registration.enabled', true)
                ->where('registration.requiresApproval', true)
                ->where('registration.requiresEmailVerification', true)
                ->where('totals.pending', 1));
    }

    public function test_only_a_superadmin_can_change_the_switches(): void
    {
        $company = Company::query()->create(['code' => 'CMP', 'name' => 'CMP Company', 'slug' => 'cmp-company', 'country_code' => 'AE']);
        $user = new User;
        $user->forceFill([
            'company_id' => $company->getKey(),
            'name' => 'Company Admin',
            'email' => 'company-admin@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->patch('/superadmin/registration', ['registration_enabled' => true, 'registration_requires_approval' => false, 'registration_requires_email_verification' => true])
            ->assertForbidden();

        self::assertFalse(PlatformSetting::current()->registration_enabled);
    }

    private function openRegistration(bool $requiresApproval, bool $requiresEmailVerification = true): void
    {
        PlatformSetting::current()->forceFill([
            'registration_enabled' => true,
            'registration_requires_approval' => $requiresApproval,
            'registration_requires_email_verification' => $requiresEmailVerification,
        ])->save();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'company_name' => 'Acme Freight LLC',
            'country_code' => 'PH',
            'city' => 'Manila',
            'name' => 'Acme Owner',
            'email' => 'owner@acme.test',
            'phone' => '+63 2 555 0101',
            'password' => 'correct-horse-42-battery',
            'password_confirmation' => 'correct-horse-42-battery',
            'terms' => true,
            ...$overrides,
        ];
    }

    private function platformAdmin(): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'registration-admin-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }

    private function withTenant(Company $company, Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $callback();
        } finally {
            $context->forget();
        }
    }
}
