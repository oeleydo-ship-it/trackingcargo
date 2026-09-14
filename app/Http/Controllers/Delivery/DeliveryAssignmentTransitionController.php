<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Enums\DeliveryAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\TransitionDeliveryAssignmentRequest;
use App\Models\DeliveryAssignment;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryAssignmentTransitionService;
use Illuminate\Http\RedirectResponse;

final class DeliveryAssignmentTransitionController extends Controller
{
    public function store(TransitionDeliveryAssignmentRequest $request, Shipment $shipment, DeliveryAssignment $assignment, DeliveryAssignmentTransitionService $transitions): RedirectResponse
    {
        abort_unless($assignment->shipment_id === $shipment->getKey(), 404);

        $transitions->transition($assignment, DeliveryAssignmentStatus::from($request->validated('status')), $request->user());

        return back()->with('success', 'Delivery status updated.');
    }
}
