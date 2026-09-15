<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterWorkspaceRequest;
use App\Services\Platform\WorkspaceRegistrationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public sign-up for a new company workspace. Only reachable while a
 * superadmin has switched it on under Superadmin → Workspaces; otherwise both
 * actions 404.
 */
final class RegisteredWorkspaceController extends Controller
{
    public function create(WorkspaceRegistrationService $registration): Response
    {
        abort_unless($registration->isOpen(), 404);

        return Inertia::render('Auth/Register', [
            'requiresApproval' => $registration->requiresApproval(),
            'requiresEmailVerification' => $registration->requiresEmailVerification(),
        ]);
    }

    public function store(RegisterWorkspaceRequest $request, WorkspaceRegistrationService $registration): RedirectResponse
    {
        $user = $registration->register($request->safe()->except(['password_confirmation', 'terms']));

        // Waiting for approval: nothing to sign in to yet. Any verification
        // email is sent when a superadmin approves the workspace — its link
        // only works for an active one.
        if ($user->company?->status === CompanyStatus::Pending) {
            return redirect()->route('login')->with('status', 'Thanks — your workspace has been created and is waiting for approval. You will be able to sign in once it is approved.');
        }

        // The Registered event is what sends the verification email; with
        // verification switched off the user is already verified and there
        // is nothing to send (and possibly no mail provider to send it with).
        if (! $user->hasVerifiedEmail()) {
            event(new Registered($user));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
