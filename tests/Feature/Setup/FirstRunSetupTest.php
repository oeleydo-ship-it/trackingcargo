<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\User;
use App\Services\Setup\InstallationService;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The first-run page that creates the platform's first superadmin on a fresh
 * production install — the one route that grants platform authority to
 * someone who is not signed in.
 */
final class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $siteInstalled = false;

    public function test_a_fresh_site_sends_visitors_to_setup(): void
    {
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/dashboard')->assertRedirect('/setup');
    }

    public function test_api_style_requests_get_a_503_instead_of_a_redirect(): void
    {
        $this->getJson('/dashboard')->assertStatus(503);
    }

    public function test_the_setup_page_renders_on_a_fresh_site(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Setup/Index'));
    }

    public function test_the_first_superadmin_is_created_and_signed_in(): void
    {
        $this->post('/setup', $this->payload())
            ->assertRedirect('/superadmin')
            ->assertSessionHasNoErrors();

        $admin = User::query()->where('email', 'owner@example.com')->firstOrFail();

        self::assertTrue($admin->is_platform_admin);
        self::assertNull($admin->company_id);
        self::assertSame(UserStatus::Active, $admin->status);
        self::assertNotNull($admin->email_verified_at);
        $context = app(TenantContext::class);
        $context->resolvePlatformBypass();

        try {
            self::assertTrue($admin->roles()->where('slug', 'super-admin')->exists(), 'The superadmin role should be attached.');
            self::assertTrue($admin->hasPermission('system-configuration.manage'));
        } finally {
            $context->forget();
        }

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_permission_catalog_is_installed_even_if_the_deploy_never_seeded(): void
    {
        self::assertSame(0, Permission::query()->count());

        $this->post('/setup', $this->payload())->assertSessionHasNoErrors();

        self::assertSame(count(config('permissions.catalog')), Permission::query()->count());
    }

    public function test_the_new_superadmin_can_sign_in_afterwards(): void
    {
        $this->post('/setup', $this->payload());
        $this->post('/logout');

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'correct-horse-42-battery'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->post('/setup', $this->payload(['email' => 'not-an-email']))->assertSessionHasErrors('email');

        self::assertSame(0, User::query()->count());
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->post('/setup', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertSessionHasErrors('password');

        self::assertSame(0, User::query()->count());
    }

    public function test_setup_closes_for_good_once_a_superadmin_exists(): void
    {
        $this->post('/setup', $this->payload());
        $this->post('/logout');

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->payload(['email' => 'attacker@example.com']))->assertNotFound();

        self::assertFalse(User::query()->where('email', 'attacker@example.com')->exists());

        // And ordinary pages stop redirecting.
        $this->get('/login')->assertOk();
    }

    public function test_removing_every_superadmin_does_not_reopen_setup(): void
    {
        $this->post('/setup', $this->payload());
        $this->post('/logout');

        User::query()->where('is_platform_admin', true)->get()->each->delete();

        // A cold cache must not be the thing keeping setup shut.
        app('cache')->store()->flush();

        $this->get('/setup')->assertNotFound();
    }

    public function test_a_second_install_attempt_loses_the_race(): void
    {
        $installation = app(InstallationService::class);

        $installation->install(['name' => 'First', 'email' => 'first@example.com', 'password' => 'correct-horse-42-battery']);

        $this->expectException(ValidationException::class);

        $installation->install(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'correct-horse-42-battery']);
    }

    public function test_production_seeding_skips_the_known_password_demo_accounts(): void
    {
        $this->app['env'] = 'production';
        config(['setup.seed_demo_data' => false]);

        // --force: in production db:seed otherwise stops to ask for confirmation.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        self::assertFalse(User::query()->where('email', 'admin@cargoflow.test')->exists());
        self::assertSame(0, User::query()->count());
        self::assertGreaterThan(0, Permission::query()->count());
        self::assertFalse(app(InstallationService::class)->isInstalled(), 'Setup must still be open for the real owner.');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Site Owner',
            'email' => 'owner@example.com',
            'password' => 'correct-horse-42-battery',
            'password_confirmation' => 'correct-horse-42-battery',
            ...$overrides,
        ];
    }
}
