<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customs;

use App\Enums\CustomsClearanceStatus;
use App\Enums\ShipmentStatusRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\TransitionCustomsClearanceRequest;
use App\Models\CustomsClearance;
use App\Models\Shipment;
use App\Services\Customs\CustomsClearanceTransitionService;
use Illuminate\Http\RedirectResponse;

final class CustomsClearanceTransitionController extends Controller
{
    public function store(TransitionCustomsClearanceRequest $request, Shipment $shipment, CustomsClearance $clearance, CustomsClearanceTransitionService $transitions): RedirectResponse
    {
        abort_unless($clearance->shipment_id === $shipment->getKey(), 404);

        $data = $request->validated();

        $transitions->transition(
            $clearance,
            CustomsClearanceStatus::from($data['status']),
            $request->user(),
            $data['idempotency_key'],
            $data['reason'] ?? null,
            isset($data['next_shipment_status']) ? ShipmentStatusRole::from($data['next_shipment_status']) : null,
        );

        return back()->with('success', 'Customs clearance status updated.');
    }
}
