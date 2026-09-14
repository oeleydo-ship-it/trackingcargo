<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\TransitionShipmentRequest;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Http\RedirectResponse;

final class ShipmentTransitionController extends Controller
{
    public function store(TransitionShipmentRequest $request, Shipment $shipment, ShipmentTransitionService $transitions, ShipmentStatusRepository $statuses): RedirectResponse
    {
        $transitions->transition(
            $shipment,
            $statuses->byCode($request->validated('status'), (int) $shipment->company_id),
            $request->user(),
            $request->validated('location'),
            $request->validated('description'),
            $request->boolean('is_public', true),
            $request->validated('occurred_at'),
        );

        return back()->with('success', 'Shipment status updated.');
    }
}
