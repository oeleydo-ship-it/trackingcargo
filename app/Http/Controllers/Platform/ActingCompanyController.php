<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Models\Company;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a platform admin step into a specific company's tenant context to
 * operate its Settings (and any other tenant-scoped screen) exactly as a
 * member of that company would — see ResolveTenant::resolvePlatformAdmin()
 * for why this is necessary rather than relying on Gate::before alone.
 *
 * Gated purely on `is_platform_admin` (`Gate::before` already grants it
 * unconditionally to any ability, so there is nothing meaningful for a
 * policy to check here); a non-admin can never reach this controller at all
 * because the route only exists inside the `auth`+`tenant` group and every
 * non-admin already has a fixed, real company they cannot escape.
 */
final class ActingCompanyController extends Controller
{
    public function store(Request $request, Company $company, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()?->is_platform_admin, 403);

        $request->session()->put(ResolveTenant::ACTING_COMPANY_SESSION_KEY, $company->getKey());

        $audit->record('platform.acting-company.started', $request->user(), $company, newValues: ['company_id' => $company->getKey()]);

        return to_route('settings.company.show')->with('success', "Now managing {$company->name}.");
    }

    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()?->is_platform_admin, 403);

        $companyId = $request->session()->pull(ResolveTenant::ACTING_COMPANY_SESSION_KEY);

        if ($companyId !== null) {
            $audit->record('platform.acting-company.stopped', $request->user(), null, oldValues: ['company_id' => $companyId]);
        }

        return to_route('settings.company.show')->with('success', 'Stopped managing that company.');
    }
}
