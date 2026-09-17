<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PublicFieldVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UpdatePublicTrackingSettingsRequest;
use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Services\Shipments\PublicTrackingFieldPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PublicTrackingSettingsController extends Controller
{
    /** @see CompanyController::show() for why the company comes from TenantContext. */
    public function index(Request $request, PublicTrackingFieldPolicy $fields): Response
    {
        $user = $request->user();
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        if ($company !== null) {
            $this->authorize('view', $company);
        } else {
            abort_unless($user?->is_platform_admin, 404);
        }

        return Inertia::render('Settings/PublicTracking', [
            'company' => $company,
            // Resolved rather than raw, so the form shows the level actually in
            // force for a company that has never saved these.
            'parties' => $company === null ? null : $this->asValues($fields->settings($company)),
            'fields' => PublicTrackingFieldPolicy::FIELDS,
            'levels' => array_map(
                static fn (PublicFieldVisibility $level): array => ['value' => $level->value, 'label' => $level->label()],
                PublicFieldVisibility::cases(),
            ),
        ]);
    }

    public function update(UpdatePublicTrackingSettingsRequest $request, AuditService $audit): RedirectResponse
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = Company::query()->findOrFail($companyId);
        $oldValues = ['public_tracking_parties' => $company->public_tracking_parties];

        $company->fill(['public_tracking_parties' => $request->validated('parties')])->save();

        $audit->record('company.publicTrackingUpdated', $request->user(), $company, oldValues: $oldValues, newValues: [
            'public_tracking_parties' => $request->validated('parties'),
        ]);

        return back()->with('success', 'Public tracking settings updated.');
    }

    /**
     * @param  array<string, array<string, PublicFieldVisibility>>  $settings
     * @return array<string, array<string, string>>
     */
    private function asValues(array $settings): array
    {
        return array_map(
            static fn (array $levels): array => array_map(
                static fn (PublicFieldVisibility $level): string => $level->value,
                $levels,
            ),
            $settings,
        );
    }
}
