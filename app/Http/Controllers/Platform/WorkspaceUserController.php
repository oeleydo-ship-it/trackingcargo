<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Services\Audit\AuditService;
use App\Services\Platform\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class WorkspaceUserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::enum(UserStatus::class)]]);

        return Inertia::render('Superadmin/Users', [
            'filters' => $filters,
            'users' => User::query()->where('is_platform_admin', false)->whereNotNull('company_id')
                ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
                ->with('company:id,name')->orderBy('name')->paginate(20, ['id', 'company_id', 'branch_id', 'name', 'email', 'status', 'last_login_at', 'two_factor_confirmed_at'])->withQueryString(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, User $user)
    {
        $this->guard($request, $user);

        return Inertia::render('Superadmin/User', [
            'managedUser' => $user->only(['id', 'company_id', 'name', 'email', 'phone', 'branch_id', 'status', 'two_factor_confirmed_at']),
            'company' => $user->company?->only(['id', 'name']),
            'branches' => Branch::withoutGlobalScopes()->where('company_id', $user->company_id)->whereNull('deleted_at')->where('status', 'active')->get(['id', 'name']),
            'roles' => Role::withoutGlobalScopes()->where('company_id', $user->company_id)->get(['id', 'name']),
            'roleIds' => DB::table('role_user')->where('user_id', $user->id)->where('company_id', $user->company_id)->pluck('role_id'),
        ]);
    }

    public function update(Request $request, User $user, AuditService $audit, UserAccessService $access)
    {
        $this->guard($request, $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $user->company_id)->whereNull('deleted_at')->where('status', 'active'))],
            'roles' => ['present', 'array'], 'roles.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('company_id', $user->company_id)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($user, $data, $access, $audit, $request): void {
            $user->forceFill(collect($data)->only(['name', 'phone', 'branch_id'])->all())->save();
            // Explicit ownership checks above; do not move users across companies.
            DB::table('role_user')->where('user_id', $user->id)->delete();
            foreach ($data['roles'] as $roleId) {
                DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id, 'company_id' => $user->company_id, 'assigned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $access->revoke($user);
            $audit->record('platform.user-updated', $request->user(), $user, newValues: $data);
        });

        return back()->with('success', 'User updated; existing sessions and tokens revoked.');
    }

    public function action(Request $request, User $user, string $action, UserAccessService $access, AuditService $audit)
    {
        $this->guard($request, $user);
        abort_unless(in_array($action, ['suspend', 'activate', 'revoke', 'invite', 'reset-password'], true), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($action === 'activate' && $user->email_verified_at === null) {
            return back()->withErrors(['action' => 'This user must accept their invitation before activation.']);
        }
        if ($action === 'invite') {
            if ($user->email_verified_at !== null) {
                return back()->withErrors(['action' => 'Only pending invitations can be resent.']);
            }
            $user->forceFill(['status' => UserStatus::Invited])->save();
            $user->notify(new UserInvited(URL::temporarySignedRoute('invitation.accept', now()->addDays(7), ['user' => $user->id]), (string) $user->company?->name));
        } elseif ($action === 'reset-password') {
            if ($user->status !== UserStatus::Active) {
                return back()->withErrors(['action' => 'Password reset requires an active account.']);
            }
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
            } catch (TransportExceptionInterface) {
                return back()->withErrors(['action' => 'Email delivery failed. Check SMTP settings and try again.']);
            }
            if ($status !== Password::RESET_LINK_SENT) {
                return back()->withErrors(['action' => trans($status)]);
            }
        } else {
            DB::transaction(function () use ($action, $user, $access): void {
                if ($action !== 'revoke') {
                    $user->forceFill(['status' => $action === 'suspend' ? UserStatus::Suspended : UserStatus::Active])->save();
                }
                $access->revoke($user);
            });
        }
        $audit->record('platform.user-'.$action, $request->user(), $user, newValues: $data);

        return back()->with('success', 'User action completed. Email invitations are queued for delivery.');
    }

    private function guard(Request $request, User $user): void
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        abort_if($user->is_platform_admin || $user->company_id === null, 404);
    }
}
