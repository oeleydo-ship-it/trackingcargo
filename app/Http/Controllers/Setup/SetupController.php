<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreSetupRequest;
use App\Services\Setup\InstallationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run page that creates the platform's first superadmin.
 *
 * Both actions 404 once the site is installed rather than redirecting or
 * explaining: after that point the page should be indistinguishable from a
 * route that does not exist.
 */
final class SetupController extends Controller
{
    public function create(InstallationService $installation): Response
    {
        $this->abortUnlessAvailable($installation);

        return Inertia::render('Setup/Index');
    }

    public function store(StoreSetupRequest $request, InstallationService $installation): RedirectResponse
    {
        $this->abortUnlessAvailable($installation);

        $user = $installation->install($request->safe()->only(['name', 'email', 'password']));

        // If a stale session belongs to someone else, it must not survive into
        // the new superadmin's.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/superadmin')->with('success', 'Your superadmin account is ready. Next, set up mail under Settings → Platform so invitations can be delivered.');
    }

    private function abortUnlessAvailable(InstallationService $installation): void
    {
        abort_if($installation->isInstalled(), 404);
        abort_unless($installation->isReady(), 503, 'Run `php artisan migrate --force` before setting up the site.');
    }
}
