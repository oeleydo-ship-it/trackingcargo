<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Setup\InstallationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every browser request to /setup until the first superadmin exists.
 *
 * Without it, a fresh deploy greets its owner with a login page nobody can
 * sign in to and no hint of what to do next.
 */
final class RedirectToSetup
{
    public function __construct(private readonly InstallationService $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup', 'setup/*', 'up')) {
            return $next($request);
        }

        // Before migrations there is nothing setup could write to; let the
        // request fail the way it normally would rather than loop to a page
        // that cannot work either.
        if ($this->installation->isInstalled() || ! $this->installation->isReady()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'This site has not been set up yet.'], 503);
        }

        return redirect('/setup');
    }
}
