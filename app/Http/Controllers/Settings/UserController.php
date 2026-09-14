<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\InviteUserRequest;
use App\Http\Requests\Identity\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\UserInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        // Not $request->user()->company_id/->company — both are null for a
        // platform admin, who has no company of their own; see
        // ResolveTenant::resolvePlatformAdmin() and StoreBranchRequest for
        // why this comes from TenantContext instead, both here and for the
        // branches list below (Branch::query() would otherwise need the
        // same fix, but it's already tenant-scoped via CompanyScope).
        $companyId = app(TenantContext::class)->companyId();

        return Inertia::render('Settings/Users/Index', [
            'users' => User::query()
                ->where('company_id', $companyId)
                ->with(['branch:id,name', 'roles:id,name,slug'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'branch_id', 'status']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(InviteUserRequest $request, UserInvitationService $invitations): RedirectResponse
    {
        $invitations->invite($request->validated(), $request->user());

        return back()->with('success', 'Invitation sent.');
    }

    public function update(UpdateUserRequest $request, User $user, AuditService $audit): RedirectResponse
    {
        $oldValues = $user->only(array_keys($request->validated()));

        $user->fill($request->validated())->save();

        $audit->record('user.updated', $request->user(), $user, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'User updated.');
    }

    public function suspend(Request $request, User $user, UserInvitationService $invitations): RedirectResponse
    {
        $this->authorize('update', $user);

        $invitations->suspend($user, $request->user());

        return back()->with('success', 'User suspended.');
    }

    public function reactivate(Request $request, User $user, UserInvitationService $invitations): RedirectResponse
    {
        $this->authorize('update', $user);

        $invitations->reactivate($user, $request->user());

        return back()->with('success', 'User reactivated.');
    }
}
