<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\TransitionShipmentRequest;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class ShipmentTransitionController extends Controller
{
    public function store(TransitionShipmentRequest $request, Shipment $shipment, ShipmentTransitionService $transitions, ShipmentStatusRepository $statuses): RedirectResponse
    {
        // Looked up in this shipment's own workflow — its branch's copy when
        // the branch is customised — so a code from elsewhere is refused.
        $to = $statuses->byCode($request->validated('status'), (int) $shipment->company_id, $shipment->branch_id !== null ? (int) $shipment->branch_id : null);

        if ($to === null) {
            throw ValidationException::withMessages(['status' => 'That status is not part of this shipment\'s workflow.']);
        }

        $transitions->transition(
            $shipment,
            $to,
            $request->user(),
            $request->validated('location'),
            $request->validated('description'),
            $request->boolean('is_public', true),
            $request->validated('occurred_at'),
        );

        return back()->with('success', 'Shipment status updated.');
    }
}
