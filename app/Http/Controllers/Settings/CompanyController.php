<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UpdateCompanyRequest;
use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    /**
     * Resolves the company from TenantContext, not $request->user()->company:
     * an ordinary user's TenantContext always equals their own company, but a
     * platform admin has none of their own — see
     * ResolveTenant::resolvePlatformAdmin(). When a platform admin hasn't
     * picked one to act as yet, $company is null and the page renders a
     * company picker instead of the settings form.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        if ($company !== null) {
            $this->authorize('view', $company);
        } else {
            abort_unless($user?->is_platform_admin, 404);
        }

        return Inertia::render('Settings/Company', [
            'company' => $company,
            'isPlatformAdmin' => (bool) $user?->is_platform_admin,
            'companies' => $user?->is_platform_admin
                ? Company::query()->orderBy('name')->get(['id', 'name', 'code'])
                : [],
        ]);
    }

    public function update(UpdateCompanyRequest $request, AuditService $audit): RedirectResponse
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = Company::query()->findOrFail($companyId);
        $oldValues = $company->only(array_keys($request->validated()));

        $company->fill($request->validated())->save();

        $audit->record('company.updated', $request->user(), $company, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Company details updated.');
    }
}
