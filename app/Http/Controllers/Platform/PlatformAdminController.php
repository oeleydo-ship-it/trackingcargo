<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\InvitePlatformAdminRequest;
use App\Models\User;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformAdminController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);

        return Inertia::render('Settings/Platform/Admins', [
            'admins' => User::query()
                ->where('is_platform_admin', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'status']),
        ]);
    }

    public function store(InvitePlatformAdminRequest $request, PlatformAdminService $admins): RedirectResponse
    {
        $admins->invite($request->validated(), $request->user());

        return back()->with('success', 'Invitation sent.');
    }

    public function suspend(Request $request, User $admin, PlatformAdminService $admins): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);

        $admins->suspend($admin, $request->user());

        return back()->with('success', 'Platform admin suspended.');
    }

    public function reactivate(Request $request, User $admin, PlatformAdminService $admins): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);

        $admins->reactivate($admin, $request->user());

        return back()->with('success', 'Platform admin reactivated.');
    }
}
