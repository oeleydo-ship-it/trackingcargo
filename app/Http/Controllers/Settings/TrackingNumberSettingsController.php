<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ShipmentMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UpdateBranchTrackingModeRequest;
use App\Http\Requests\Identity\UpdateTrackingNumberSettingsRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Models\TrackingNumberFormat;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Services\Shipments\TrackingNumberRules;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TrackingNumberSettingsController extends Controller
{
    /**
     * Resolves the company from TenantContext, not $request->user()->company —
     * see CompanyController::show() for why a platform admin needs this.
     */
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

        return Inertia::render('Settings/TrackingNumbers', [
            'company' => $company,
            'tokens' => TrackingNumberFormatter::TOKENS,
            'branches' => $company === null ? [] : Branch::query()->orderByDesc('is_head_office')->orderBy('name')->get(['id', 'name', 'tracking_prefix', 'default_tracking_mode']),
            'modes' => array_map(fn (ShipmentMode $mode): string => $mode->value, ShipmentMode::cases()),
            // Branch/mode overrides of the default pattern above.
            'formats' => $company === null ? [] : app(TrackingNumberRules::class)->forCompany((int) $company->getKey())
                ->map(fn (TrackingNumberFormat $rule): array => [
                    'id' => $rule->getKey(),
                    'branch_id' => $rule->branch_id,
                    'branch_name' => $rule->branch?->name,
                    'mode' => $rule->mode?->value,
                    'format' => $rule->format,
                    'sequence_padding' => $rule->sequence_padding,
                ])->all(),
            // Drives a real preview of the pattern rather than a made-up one.
            'sampleBranchPrefix' => $company === null
                ? null
                : Branch::query()->orderByDesc('is_head_office')->orderBy('name')->value('tracking_prefix'),
        ]);
    }

    public function update(UpdateTrackingNumberSettingsRequest $request, AuditService $audit): RedirectResponse
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = Company::query()->findOrFail($companyId);
        $oldValues = $company->only(array_keys($request->validated()));

        $company->fill($request->validated())->save();

        $audit->record('company.trackingSettingsUpdated', $request->user(), $company, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Tracking number settings updated.');
    }

    /** One branch's default numbering method; blank makes it follow the company's. */
    public function updateBranch(UpdateBranchTrackingModeRequest $request, Branch $branch, AuditService $audit): RedirectResponse
    {
        $old = $branch->default_tracking_mode;
        $new = $request->validated('default_tracking_mode');

        $branch->forceFill(['default_tracking_mode' => $new])->save();

        $audit->record('branch.trackingModeUpdated', $request->user(), $branch, oldValues: ['default_tracking_mode' => $old], newValues: ['default_tracking_mode' => $new]);

        return back()->with('success', "{$branch->name} default numbering method updated.");
    }
}
