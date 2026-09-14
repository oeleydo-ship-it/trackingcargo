<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenant
{
    public const string ACTING_COMPANY_SESSION_KEY = 'platform_acting_company_id';

    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);
        abort_if($user->status !== UserStatus::Active, Response::HTTP_FORBIDDEN, 'User account is not active.');

        if ($user->is_platform_admin) {
            $this->resolvePlatformAdmin($request);
        } else {
            abort_if($user->company_id === null, Response::HTTP_FORBIDDEN, 'User is not assigned to a company.');
            abort_if($user->company === null || $user->company->status !== CompanyStatus::Active, Response::HTTP_FORBIDDEN, 'Company account is not active.');
            $this->tenantContext->resolveCompany((int) $user->company_id);
            if (! $user->hasActiveBranchAccess()) {
                $this->tenantContext->forget();
                if ($request->expectsJson()) {
                    abort(Response::HTTP_FORBIDDEN, 'Branch access is not active.');
                }
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => 'Your assigned branch is inactive or has been removed. Ask your administrator to restore it or assign you to an active branch.']);
            }
        }

        try {
            return $next($request);
        } finally {
            $this->tenantContext->forget();
        }
    }

    /**
     * A platform admin belongs to no company (`company_id` is always null),
     * so every tenant-scoped screen (Settings, CRM, shipments, ...) is
     * otherwise unreachable to them — not because Gate::before doesn't grant
     * the ability (it does, unconditionally), but because CompanyScope has
     * nothing to scope to and every `BelongsToCompany` create hook has
     * nothing to fill in. "Acting as" a specific company (chosen via
     * Platform\ActingCompanyController and stored in the session — not the
     * database, so it never touches the user's real, company-less identity)
     * resolves tenant context to that company instead of the platform
     * bypass, making every existing tenant-scoped query/service behave
     * exactly as it would for a real member of that company. Falls back to
     * the platform bypass (unscoped, cross-company) if no company is being
     * acted as, or if the stored id no longer refers to a real company.
     */
    private function resolvePlatformAdmin(Request $request): void
    {
        $actingCompanyId = $request->session()->get(self::ACTING_COMPANY_SESSION_KEY);

        if ($actingCompanyId !== null && Company::query()->whereKey($actingCompanyId)->exists()) {
            $this->tenantContext->resolveCompany((int) $actingCompanyId);

            return;
        }

        $this->tenantContext->resolvePlatformBypass();
    }
}
