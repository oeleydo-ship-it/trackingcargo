<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\PermissionCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public, self-service workspace sign-up.
 *
 * Builds the same thing a superadmin gets from Superadmin → Workspaces — a
 * company, its head-office branch, the default shipment workflow (provisioned
 * by Company itself), and a workspace-administrator role — but with the person
 * signing up as that administrator, choosing their own password rather than
 * accepting an emailed invitation.
 *
 * Whether it is allowed at all, and whether the new workspace starts active or
 * waits for approval, are the superadmin's switches on PlatformSetting.
 */
final readonly class WorkspaceRegistrationService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditService $audit,
    ) {}

    public function isOpen(): bool
    {
        return (bool) PlatformSetting::current()->registration_enabled;
    }

    public function requiresApproval(): bool
    {
        return (bool) PlatformSetting::current()->registration_requires_approval;
    }

    public function requiresEmailVerification(): bool
    {
        return (bool) PlatformSetting::current()->registration_requires_email_verification;
    }

    /**
     * @param  array{company_name: string, country_code: string, city: string, name: string, email: string, phone?: string|null, password: string}  $data
     */
    public function register(array $data): User
    {
        $settings = PlatformSetting::current();
        $status = $settings->registration_requires_approval ? CompanyStatus::Pending : CompanyStatus::Active;

        try {
            return DB::transaction(function () use ($data, $settings, $status): User {
                $company = Company::query()->create([
                    'name' => $data['company_name'],
                    'code' => $this->uniqueCompanyCode($data['company_name']),
                    'slug' => $this->uniqueSlug($data['company_name']),
                    'country_code' => $data['country_code'],
                    'timezone' => $settings->default_timezone ?: 'UTC',
                    'default_currency' => $settings->default_currency ?: 'USD',
                    'status' => $status,
                ]);

                $this->tenantContext->resolveCompany((int) $company->getKey());

                Branch::query()->create([
                    'name' => 'Head Office',
                    'code' => 'HQ',
                    'tracking_prefix' => 'HQ',
                    'country_code' => $data['country_code'],
                    'city' => $data['city'],
                    'timezone' => $company->timezone,
                    'is_head_office' => true,
                ]);

                PermissionCatalog::ensureInstalled();

                $role = Role::query()->create(['name' => 'Workspace administrator', 'slug' => 'workspace-admin', 'is_system' => true]);
                $role->permissions()->sync(Permission::query()->where('platform_only', false)->pluck('id'));

                $user = new User;
                $user->forceFill([
                    'company_id' => $company->getKey(),
                    // Company-wide access, like a superadmin-created workspace
                    // administrator: they manage every branch they later add.
                    'branch_id' => null,
                    'is_platform_admin' => false,
                    'name' => $data['name'],
                    'email' => Str::lower($data['email']),
                    'phone' => $data['phone'] ?? null,
                    'password' => $data['password'],
                    'status' => UserStatus::Active,
                    // With verification switched off (no mail provider) the
                    // address is taken as given; otherwise it is confirmed by
                    // the emailed link.
                    'email_verified_at' => $settings->registration_requires_email_verification ? null : now(),
                ]);
                $user->save();

                $user->roles()->attach($role->getKey(), ['company_id' => $company->getKey(), 'assigned_by' => null]);

                $this->audit->record('workspace.registered', $user, $company, newValues: [
                    'code' => $company->code,
                    'status' => $status->value,
                    'admin_email' => $user->email,
                ]);

                return $user;
            });
        } finally {
            // Sign-up is a guest request with no tenant of its own; the
            // context was only resolved above to create the new company's
            // records, and must not outlive it.
            $this->tenantContext->forget();
        }
    }

    /**
     * A short company code for tracking numbers and the superadmin list, taken
     * from the name the person typed — the form does not ask for one, since
     * someone signing up has no idea what it is used for.
     */
    private function uniqueCompanyCode(string $name): string
    {
        $letters = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)));
        $base = substr($letters !== '' ? $letters : 'WS', 0, 6);
        $code = $base;
        $suffix = 1;

        while (Company::query()->withTrashed()->where('code', $code)->exists()) {
            $code = substr($base, 0, 6 - strlen((string) ++$suffix)).$suffix;
        }

        return $code;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = substr($base, 0, 90);
        $slug = $base;
        $suffix = 1;

        while (Company::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
