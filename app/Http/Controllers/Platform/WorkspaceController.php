<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\UserInvitationService;
use App\Services\Platform\UserAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(CompanyStatus::class)]]);

        return Inertia::render('Superadmin/Workspaces', [
            'filters' => $filters,
            'workspaces' => Company::query()->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->withCount('users')->orderBy('name')->paginate(20)->withQueryString(),
            'totals' => [
                'workspaces' => Company::query()->count(),
                'active' => Company::query()->where('status', 'active')->count(),
                'users' => User::query()->where('is_platform_admin', false)->count(),
                'suspended' => Company::query()->where('status', 'suspended')->count(),
                'pending' => Company::query()->where('status', CompanyStatus::Pending)->count(),
            ],
            'registration' => [
                'enabled' => (bool) PlatformSetting::current()->registration_enabled,
                'requiresApproval' => (bool) PlatformSetting::current()->registration_requires_approval,
                'requiresEmailVerification' => (bool) PlatformSetting::current()->registration_requires_email_verification,
            ],
        ]);
    }

    public function store(Request $request, UserInvitationService $invitations, AuditService $audit)
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('companies', 'code')],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('companies', 'slug')],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['required', 'timezone'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'branch_name' => ['required', 'string', 'max:255'],
            'branch_code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'city' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);
        $context = app(TenantContext::class);
        $previous = $context->companyId();
        try {
            $company = DB::transaction(function () use ($data, $context, $request, $audit, $invitations) {
                $company = Company::query()->create(collect($data)->only(['name', 'code', 'slug', 'country_code', 'timezone', 'default_currency'])->all());
                $context->resolveCompany((int) $company->id);
                Branch::query()->create(['name' => $data['branch_name'], 'code' => $data['branch_code'], 'tracking_prefix' => $data['branch_code'], 'country_code' => $data['country_code'], 'city' => $data['city'], 'timezone' => $data['timezone'], 'is_head_office' => true]);
                $role = Role::query()->create(['name' => 'Workspace administrator', 'slug' => 'workspace-admin', 'is_system' => true]);
                $role->permissions()->sync(Permission::query()->where('platform_only', false)->pluck('id'));
                $user = $invitations->invite(['name' => $data['admin_name'], 'email' => $data['admin_email']], $request->user());
                $user->roles()->attach($role->id, ['company_id' => $company->id, 'assigned_by' => $request->user()->id]);
                $audit->record('workspace.created', $request->user(), $company, newValues: ['code' => $company->code]);

                return $company;
            });
        } finally {
            $previous === null ? $context->resolvePlatformBypass() : $context->resolveCompany($previous);
        }

        return back()->with('success', "Workspace {$company->name} created. Administrator invitation queued.");
    }

    public function update(Request $request, Company $company, AuditService $audit, UserAccessService $access)
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'status' => ['required', Rule::enum(CompanyStatus::class)], 'reason' => ['required', 'string', 'max:500']]);
        $wasPending = $company->status === CompanyStatus::Pending;

        DB::transaction(function () use ($company, $data, $request, $audit, $access): void {
            $old = $company->only(['name', 'status']);
            $company->fill(collect($data)->only(['name', 'status'])->all())->save();
            if ($company->status !== CompanyStatus::Active) {
                User::query()->where('company_id', $company->id)->where('is_platform_admin', false)->each(fn (User $user) => $access->revoke($user));
            }
            $audit->record('workspace.updated', $request->user(), $company, oldValues: $old, newValues: $data);
        });

        // A self-registered workspace just approved: its administrator could
        // not verify their email while it was pending (the link only works for
        // an active workspace), so the verification email goes out now — or,
        // with verification switched off, they are simply marked verified.
        if ($wasPending && $company->status === CompanyStatus::Active) {
            $unverified = User::query()->where('company_id', $company->id)->whereNull('email_verified_at');

            if (! PlatformSetting::current()->registration_requires_email_verification) {
                $unverified->update(['email_verified_at' => now()]);

                return back()->with('success', "Workspace {$company->name} approved. Its administrator can sign in now.");
            }

            $unverified->each(fn (User $user) => $user->sendEmailVerificationNotification());

            return back()->with('success', "Workspace {$company->name} approved. Its administrator has been emailed a verification link.");
        }

        return back()->with('success', 'Workspace updated. Inactive workspaces cannot sign in; their sessions and tokens have been revoked.');
    }
}
