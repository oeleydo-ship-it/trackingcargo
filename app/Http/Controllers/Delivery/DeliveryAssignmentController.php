<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDeliveryAssignmentRequest;
use App\Models\DeliveryAssignment;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryAssignmentService;
use App\Services\Delivery\DeliveryAssignmentTransitionMap;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DeliveryAssignmentController extends Controller
{
    public function show(Shipment $shipment, DeliveryAssignment $assignment): Response
    {
        $this->authorize('view', $assignment);
        abort_unless($assignment->shipment_id === $shipment->getKey(), 404);

        $assignment->load([
            'driver.user:id,name,email',
            'vehicle',
            'zone',
            'attempts.actor:id,name',
            'attempts.documents',
        ]);

        return Inertia::render('Delivery/Assignments/Show', [
            'shipment' => ['id' => $shipment->getKey(), 'tracking_number' => $shipment->tracking_number, 'status' => $shipment->status, 'cod_amount' => $shipment->cod_amount, 'currency' => $shipment->currency],
            'assignment' => $assignment,
            'allowedTransitions' => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                DeliveryAssignmentTransitionMap::allowedFrom($assignment->status),
            ),
        ]);
    }

    public function store(StoreDeliveryAssignmentRequest $request, Shipment $shipment, DeliveryAssignmentService $assignments): RedirectResponse
    {
        $assignment = $assignments->assign($shipment, $request->validated(), $request->user());

        return to_route('shipments.deliveryAssignments.show', [$shipment, $assignment])->with('success', 'Delivery assigned.');
    }
}
