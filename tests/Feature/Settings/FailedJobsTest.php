<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * failed_jobs has no company_id — it is a genuinely platform-wide table
 * (see FailedJobController's docblock) — so this is gated to
 * is_platform_admin, not any company-scoped permission.
 */
final class FailedJobsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * `failed-jobs.manage` is platform_only in the real catalog
     * (config/permissions.php), which AuthorizationSeeder's per-company
     * role sync explicitly excludes (`Permission::where('platform_only',
     * false)`) even for company-admin's wildcard — so no company-scoped
     * role can ever hold it through the real seeding flow. This test
     * doesn't attempt to grant it (the CreatesTenants::grantPermissions()
     * test helper always creates an ad-hoc platform_only=false row, which
     * would misrepresent that constraint) — it just confirms an ordinary
     * company-scoped user, who structurally cannot hold this permission,
     * is blocked.
     */
    public function test_a_company_scoped_user_cannot_view_failed_jobs(): void
    {
        $company = $this->createCompany('FJA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'billing.view', 'webhooks.view']);

        $this->actingAs($actor)->get('/settings/failed-jobs')->assertForbidden();
    }

    public function test_a_platform_admin_can_view_and_remove_a_failed_job(): void
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'fja-admin@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendWebhookJob']),
            'exception' => "RuntimeException: simulated\n#0 stack trace",
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/settings/failed-jobs');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('jobs.data', 1)->where('jobs.data.0.uuid', $uuid));

        $this->actingAs($admin)->delete("/settings/failed-jobs/{$uuid}")->assertRedirect();

        self::assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }
}
