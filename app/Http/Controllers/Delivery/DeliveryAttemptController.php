<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Enums\DeliveryAttemptOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDeliveryAttemptRequest;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryAttempt;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryAttemptPdfService;
use App\Services\Delivery\DeliveryAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class DeliveryAttemptController extends Controller
{
    public function store(StoreDeliveryAttemptRequest $request, Shipment $shipment, DeliveryAssignment $assignment, DeliveryAttemptService $attempts): RedirectResponse
    {
        abort_unless($assignment->shipment_id === $shipment->getKey(), 404);

        $data = $request->validated();

        $attempts->record(
            $assignment,
            DeliveryAttemptOutcome::from($data['outcome']),
            $request->user(),
            $data['idempotency_key'],
            $data,
            $request->file('signature'),
            $request->file('photos', []),
        );

        return to_route('shipments.deliveryAssignments.show', [$shipment, $assignment])->with('success', 'Delivery attempt recorded.');
    }

    public function pod(Shipment $shipment, DeliveryAssignment $assignment, DeliveryAttempt $attempt, DeliveryAttemptPdfService $pdf): Response
    {
        $this->authorize('view', $assignment);
        abort_unless(
            $assignment->shipment_id === $shipment->getKey() && $attempt->delivery_assignment_id === $assignment->getKey(),
            404,
        );

        return response($pdf->render($attempt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"POD-{$shipment->tracking_number}.pdf\"",
        ]);
    }
}
