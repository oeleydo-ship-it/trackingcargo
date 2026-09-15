<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The superadmin's on/off switches for public workspace sign-up.
 */
final class RegistrationSettingsController extends Controller
{
    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()?->is_platform_admin, 403);

        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'registration_requires_approval' => ['required', 'boolean'],
            'registration_requires_email_verification' => ['required', 'boolean'],
        ]);

        $settings = PlatformSetting::current();
        $old = $settings->only(array_keys($data));

        $settings->fill($data)->save();

        $audit->record('platform.registration-updated', $request->user(), $settings, oldValues: $old, newValues: $data);

        return back()->with('success', $settings->registration_enabled
            ? 'Workspace sign-up is on'.($settings->registration_requires_approval ? ' — new workspaces wait for your approval.' : ' — new workspaces are active immediately.')
            : 'Workspace sign-up is off.');
    }
}
