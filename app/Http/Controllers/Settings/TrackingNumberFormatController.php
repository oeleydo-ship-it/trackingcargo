<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TrackingNumberFormatRequest;
use App\Models\TrackingNumberFormat;
use App\Services\Audit\AuditService;
use App\Services\Shipments\TrackingNumberRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The branch/mode tracking-number formats under Settings → Tracking numbers.
 */
final class TrackingNumberFormatController extends Controller
{
    public function store(TrackingNumberFormatRequest $request, AuditService $audit, TrackingNumberRules $rules): RedirectResponse
    {
        $format = TrackingNumberFormat::query()->create($request->safe()->only(['branch_id', 'mode', 'format', 'sequence_padding']));

        $audit->record('tracking-number-format.created', $request->user(), $format, newValues: $format->only(['branch_id', 'mode', 'format', 'sequence_padding']));
        $rules->forget();

        return back()->with('success', "Format added for {$format->describe()}.");
    }

    public function update(TrackingNumberFormatRequest $request, TrackingNumberFormat $trackingNumberFormat, AuditService $audit, TrackingNumberRules $rules): RedirectResponse
    {
        $fields = $request->safe()->only(['branch_id', 'mode', 'format', 'sequence_padding']);
        $oldValues = $trackingNumberFormat->only(array_keys($fields));

        $trackingNumberFormat->fill($fields)->save();

        $audit->record('tracking-number-format.updated', $request->user(), $trackingNumberFormat, oldValues: $oldValues, newValues: $fields);
        $rules->forget();

        return back()->with('success', 'Format updated.');
    }

    public function destroy(Request $request, TrackingNumberFormat $trackingNumberFormat, AuditService $audit, TrackingNumberRules $rules): RedirectResponse
    {
        $this->authorize('update', $trackingNumberFormat->company);

        $description = $trackingNumberFormat->describe();

        $audit->record('tracking-number-format.deleted', $request->user(), $trackingNumberFormat, oldValues: $trackingNumberFormat->only(['branch_id', 'mode', 'format', 'sequence_padding']));

        $trackingNumberFormat->delete();
        $rules->forget();

        // Shipments already numbered keep their numbers; only new bookings for
        // that branch and mode fall back to the next matching format.
        return back()->with('success', "Format for {$description} removed. New bookings use the next matching format.");
    }
}
