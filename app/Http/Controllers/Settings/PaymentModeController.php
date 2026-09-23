<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Services\Shipments\PaymentModeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentModeController extends Controller
{
    public function index(Request $request, PaymentModeCatalog $catalog): Response
    {
        $company = $catalog->company();
        $this->authorize('view', $company);

        return Inertia::render('Settings/PaymentModes', [
            'modes' => $catalog->all($company),
            'canManage' => $request->user()?->can('update', $company) ?? false,
        ]);
    }

    public function store(Request $request, PaymentModeCatalog $catalog, AuditService $audit): RedirectResponse
    {
        $company = $catalog->company();
        $this->authorize('update', $company);
        $data = $request->validate(['label' => ['required', 'string', 'max:60']]);
        $label = trim($data['label']);
        $value = Str::slug($label, '_');
        if ($value === '' || strlen($value) > 20) {
            throw ValidationException::withMessages(['label' => 'Use a shorter name with letters or numbers.']);
        }

        DB::transaction(function () use ($company, $catalog, $audit, $request, $label, $value): void {
            $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $modes = $catalog->all($company);
            if (count($modes) >= 30 || in_array($value, array_column($modes, 'value'), true)) {
                throw ValidationException::withMessages(['label' => 'This payment mode already exists, or the limit of 30 modes has been reached.']);
            }
            $modes[] = ['value' => $value, 'label' => $label, 'active' => true];
            $company->settings = [...($company->settings ?? []), 'shipment_payment_modes' => $modes];
            $company->save();
            $audit->record('shipment-payment-mode.created', $request->user(), $company, newValues: ['value' => $value, 'label' => $label]);
        });

        return back()->with('success', 'Payment mode added.');
    }

    public function update(Request $request, string $mode, PaymentModeCatalog $catalog, AuditService $audit): RedirectResponse
    {
        $company = $catalog->company();
        $this->authorize('update', $company);
        $data = $request->validate(['label' => ['required', 'string', 'max:60'], 'active' => ['required', 'boolean']]);
        if (trim($data['label']) === '') {
            throw ValidationException::withMessages(['label' => 'Enter a payment mode name.']);
        }

        DB::transaction(function () use ($company, $catalog, $audit, $request, $mode, $data): void {
            $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $modes = $catalog->all($company);
            $index = array_search($mode, array_column($modes, 'value'), true);
            if ($index === false) {
                abort(404);
            }
            $old = $modes[$index];
            $modes[$index] = ['value' => $mode, 'label' => trim($data['label']), 'active' => (bool) $data['active']];
            $company->settings = [...($company->settings ?? []), 'shipment_payment_modes' => $modes];
            $company->save();
            $audit->record('shipment-payment-mode.updated', $request->user(), $company, oldValues: $old, newValues: $modes[$index]);
        });

        return back()->with('success', 'Payment mode updated.');
    }
}
