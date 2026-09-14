<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\AssignRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Http\RedirectResponse;

final class UserRoleController extends Controller
{
    public function store(AssignRoleRequest $request, User $user, RoleAssignmentService $roles): RedirectResponse
    {
        $role = Role::query()->findOrFail($request->validated('role_id'));

        $roles->assign($user, $role, $request->user());

        return back()->with('success', 'Role assigned.');
    }

    public function destroy(User $user, Role $role, RoleAssignmentService $roles): RedirectResponse
    {
        $this->authorize('update', $user);

        $roles->revoke($user, $role, request()->user());

        return back()->with('success', 'Role revoked.');
    }
}
