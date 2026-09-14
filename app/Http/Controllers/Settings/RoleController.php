<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\StoreRoleRequest;
use App\Http\Requests\Identity\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('Settings/Roles/Index', [
            'roles' => Role::query()->with('permissions:id,slug,name,group')->orderBy('name')->get(),
            'permissions' => Permission::query()->where('platform_only', false)->orderBy('group')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreRoleRequest $request, AuditService $audit): RedirectResponse
    {
        $role = DB::transaction(function () use ($request): Role {
            $role = Role::query()->create($request->safe()->only(['name', 'slug']));
            $role->permissions()->sync($request->validated('permissions', []));

            return $role;
        });

        $audit->record('role.created', $request->user(), $role, newValues: $request->validated());

        return back()->with('success', 'Role created.');
    }

    public function update(UpdateRoleRequest $request, Role $role, AuditService $audit): RedirectResponse
    {
        $oldValues = $role->only(['name', 'slug']);

        DB::transaction(function () use ($request, $role): void {
            $role->fill($request->safe()->only(['name', 'slug']))->save();
            $role->permissions()->sync($request->validated('permissions', []));
        });

        $audit->record('role.updated', $request->user(), $role, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $role);

        $role->delete();

        $audit->record('role.deleted', request()->user(), $role);

        return back()->with('success', 'Role removed.');
    }
}
