<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * First-run installation: the one moment a superadmin can be created without
 * already being one.
 *
 * Every other way to get platform-admin authority requires an existing
 * platform admin to grant it (PlatformAdminService::invite). A fresh
 * deployment has none, so something has to break that loop — and whatever
 * does is, by construction, the most privileged unauthenticated action in the
 * app. The rules here exist to keep it that narrow:
 *
 * - It is only available while no platform admin has ever existed. Soft-
 *   deleted and suspended admins still count, so removing admins can never
 *   reopen setup for a stranger.
 * - Two simultaneous submissions cannot both succeed.
 *
 * It deliberately asks for no proof of server ownership: the site is deployed
 * automatically from GitHub, with no shell step in which to read a code. The
 * first visitor to a fresh deployment creates the superadmin, so complete
 * /setup right after the first deploy (or before the site is publicly
 * reachable).
 */
final readonly class InstallationService
{
    /**
     * Once installed, a site never becomes uninstalled, so only the positive
     * answer is cached — the check then costs nothing on every request for the
     * life of the site, while a fresh install still re-checks each time.
     */
    private const string INSTALLED_CACHE_KEY = 'setup:installed';

    private const string LOCK_KEY = 'setup:install';

    public function __construct(
        private TenantContext $tenantContext,
        private AuditService $audit,
    ) {}

    public function isInstalled(): bool
    {
        try {
            if (Cache::get(self::INSTALLED_CACHE_KEY) === true) {
                return true;
            }
        } catch (Throwable) {
            // A cache store that is not reachable yet must not take the site
            // down; fall through to the database.
        }

        $installed = $this->platformAdminExists();

        if ($installed) {
            $this->rememberInstalled();
        }

        return $installed;
    }

    /**
     * Whether the database is migrated far enough for setup to work at all.
     * Before `migrate` has run there is nothing to install into.
     */
    public function isReady(): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasTable('roles') && Schema::hasTable('permissions');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Creates the first superadmin.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function install(array $data): User
    {
        try {
            return Cache::lock(self::LOCK_KEY, 30)->block(10, fn (): User => $this->installLocked($data));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'email' => 'Setup is already being completed from another session. Reload the page in a moment.',
            ]);
        }
    }

    private function installLocked(array $data): User
    {
        // Re-checked under the lock and against the database, never the
        // cache: the request that loses a race must see the winner's admin.
        if ($this->platformAdminExists()) {
            throw ValidationException::withMessages([
                'email' => 'This site has already been set up. Sign in instead.',
            ]);
        }

        $this->tenantContext->resolvePlatformBypass();

        try {
            $user = DB::transaction(function () use ($data): User {
                $role = $this->ensureSuperAdminRole();

                $user = new User;
                $user->forceFill([
                    'company_id' => null,
                    'branch_id' => null,
                    'is_platform_admin' => true,
                    'name' => $data['name'],
                    'email' => Str::lower($data['email']),
                    'password' => Hash::make($data['password']),
                    'status' => UserStatus::Active,
                    // A fresh install has no mail provider configured yet to
                    // send a verification link through; mail is set up by
                    // this admin afterwards, under Settings → Platform.
                    'email_verified_at' => now(),
                ]);
                $user->save();

                $user->roles()->syncWithoutDetaching([
                    $role->getKey() => ['company_id' => null, 'assigned_by' => null],
                ]);

                $this->audit->record('platform.installed', $user, $user, newValues: $user->only(['name', 'email']));

                return $user;
            });
        } finally {
            $this->tenantContext->forget();
        }

        $this->rememberInstalled();

        return $user;
    }

    /**
     * The permission catalog and the platform super-admin role, created if the
     * deploy never ran AuthorizationSeeder.
     *
     * Only the platform-level pieces are written. Company system roles are
     * AuthorizationSeeder's job, and it re-syncs their permissions — running
     * that here would silently reset permission edits on any tenant that
     * already existed.
     */
    private function ensureSuperAdminRole(): Role
    {
        foreach (config('permissions.catalog') as $slug => [$name, $group, $platformOnly]) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group, 'platform_only' => $platformOnly],
            );
        }

        $role = Role::query()->firstOrCreate(
            ['scope_key' => 'platform', 'slug' => 'super-admin'],
            ['company_id' => null, 'name' => 'Super Admin', 'is_system' => true],
        );

        $role->permissions()->syncWithoutDetaching(Permission::query()->pluck('id'));

        return $role;
    }

    private function platformAdminExists(): bool
    {
        try {
            return User::withTrashed()->where('is_platform_admin', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Records that the site is installed without querying for it. Public so
     * the test suite, whose tenants never include a platform admin, can run
     * against an installed site by default.
     */
    public function rememberInstalled(): void
    {
        try {
            Cache::forever(self::INSTALLED_CACHE_KEY, true);
        } catch (Throwable) {
            // The database check still answers correctly without the cache.
        }
    }
}
