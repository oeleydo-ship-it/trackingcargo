<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UpdateBatchNumberSettingsRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BatchNumberSettingsController extends Controller
{
    /** @see CompanyController::show() for why the company comes from TenantContext. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        if ($company !== null) {
            $this->authorize('view', $company);
        } else {
            abort_unless($user?->is_platform_admin, 404);
        }

        return Inertia::render('Settings/Batches', [
            'company' => $company,
            'tokens' => TrackingNumberFormatter::TOKENS,
            'sampleBranchPrefix' => $company === null
                ? null
                : Branch::query()->orderByDesc('is_head_office')->orderBy('name')->value('tracking_prefix'),
        ]);
    }

    public function update(UpdateBatchNumberSettingsRequest $request, AuditService $audit): RedirectResponse
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = Company::query()->findOrFail($companyId);
        $oldValues = $company->only(array_keys($request->validated()));

        $company->fill($request->validated())->save();

        $audit->record('company.batchSettingsUpdated', $request->user(), $company, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Batch number settings updated.');
    }
}
