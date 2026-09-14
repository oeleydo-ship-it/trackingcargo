<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Settings -> Platform: branding, the outgoing mailer, and payment gateway
 * credentials. None of this is tenant data (see the platform_settings
 * migration), so every route is gated to system-configuration.manage, which
 * is platform_only in the real catalog (config/permissions.php) and so —
 * like failed-jobs.manage, see FailedJobsTest — structurally unreachable by
 * any company-scoped role, only ever satisfied via is_platform_admin.
 */
final class PlatformSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_company_scoped_user_cannot_view_or_change_platform_settings(): void
    {
        $company = $this->createCompany('PSA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['*']);

        $this->actingAs($actor)->get('/settings/platform')->assertForbidden();
        $this->actingAs($actor)->patch('/settings/platform/general', ['site_name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($actor)->post('/settings/platform/branding', [])->assertForbidden();
        $this->actingAs($actor)->patch('/settings/platform/smtp', ['smtp_host' => 'evil.example.test'])->assertForbidden();
        $this->actingAs($actor)->patch('/settings/platform/stripe', ['stripe_secret_key' => 'sk_stolen'])->assertForbidden();
    }

    public function test_a_platform_admin_can_view_and_update_general_settings(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->get('/settings/platform')->assertOk()->assertInertia(fn ($page) => $page
            ->where('settings.site_name', 'CargoFlow')
            ->etc());

        $this->actingAs($admin)->patch('/settings/platform/general', [
            'site_name' => 'Starlink Freight',
            'support_email' => 'help@starlink.test',
            'default_timezone' => 'Asia/Dubai',
            'default_currency' => 'AED',
        ])->assertRedirect();

        $settings = PlatformSetting::current();
        self::assertSame('Starlink Freight', $settings->site_name);
        self::assertSame('help@starlink.test', $settings->support_email);
        self::assertSame('AED', $settings->default_currency);
    }

    public function test_a_platform_admin_can_upload_and_remove_a_logo(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post('/settings/platform/branding', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect();

        $settings = PlatformSetting::current();
        self::assertNotNull($settings->logo_path);

        $this->actingAs($admin)->get('/settings/platform')->assertInertia(fn ($page) => $page->where('settings.logo_url', fn (?string $url): bool => $url !== null)->etc());

        $this->actingAs($admin)->post('/settings/platform/branding', ['remove_logo' => true])->assertRedirect();

        self::assertNull(PlatformSetting::current()->refresh()->logo_path);
    }

    public function test_an_smtp_password_left_blank_keeps_the_stored_one(): void
    {
        $admin = $this->platformAdmin();

        $payload = [
            'smtp_host' => 'smtp.mailgun.org',
            'smtp_port' => 587,
            'smtp_username' => 'postmaster@starlink.test',
            'smtp_password' => 'first-secret',
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'no-reply@starlink.test',
            'smtp_from_name' => 'Starlink Freight',
        ];

        $this->actingAs($admin)->patch('/settings/platform/smtp', $payload)->assertRedirect();
        self::assertSame('first-secret', PlatformSetting::current()->smtp_password);

        // Editing the host again without retyping the password must not wipe
        // it — the form never redisplays a stored secret, so a blank field
        // always means "unchanged", not "clear it".
        $this->actingAs($admin)->patch('/settings/platform/smtp', [...$payload, 'smtp_host' => 'smtp2.mailgun.org', 'smtp_password' => ''])->assertRedirect();

        $settings = PlatformSetting::current()->refresh();
        self::assertSame('smtp2.mailgun.org', $settings->smtp_host);
        self::assertSame('first-secret', $settings->smtp_password);

        // Stored encrypted, not in the clear, in the actual database column.
        $raw = DB::table('platform_settings')->where('id', $settings->getKey())->value('smtp_password');
        self::assertStringNotContainsString('first-secret', (string) $raw);
    }

    public function test_the_smtp_password_is_redacted_in_the_audit_log(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->patch('/settings/platform/smtp', [
            'smtp_host' => 'smtp.mailgun.org',
            'smtp_port' => 587,
            'smtp_password' => 'super-secret-password',
            'smtp_from_address' => 'no-reply@starlink.test',
        ])->assertRedirect();

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'platform-settings.smtp-updated')->latest('id')->firstOrFail();

        self::assertSame('[changed]', $entry->new_values['smtp_password']);
        self::assertStringNotContainsString('super-secret-password', json_encode($entry->new_values));
    }

    public function test_a_platform_admin_can_send_a_test_email(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post('/settings/platform/smtp/test', ['to' => 'ops@starlink.test'])
            ->assertRedirect();

        self::assertNotNull(AuditLog::withoutGlobalScopes()->where('action', 'platform-settings.smtp-test-sent')->first());
    }

    public function test_a_stripe_secret_left_blank_keeps_the_stored_one(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->patch('/settings/platform/stripe', [
            'stripe_publishable_key' => 'pk_test_first',
            'stripe_secret_key' => 'sk_test_first',
            'stripe_webhook_secret' => 'whsec_first',
        ])->assertRedirect();

        $this->actingAs($admin)->patch('/settings/platform/stripe', [
            'stripe_publishable_key' => 'pk_test_second',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
        ])->assertRedirect();

        $settings = PlatformSetting::current()->refresh();
        self::assertSame('pk_test_second', $settings->stripe_publishable_key);
        self::assertSame('sk_test_first', $settings->stripe_secret_key);
        self::assertSame('whsec_first', $settings->stripe_webhook_secret);
    }

    private function platformAdmin(): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'platform-settings-admin-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }
}
