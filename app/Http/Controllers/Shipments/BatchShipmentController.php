<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreBatchShipmentRequest;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Services\Shipments\ShipmentBatchService;
use Illuminate\Http\RedirectResponse;

final class BatchShipmentController extends Controller
{
    public function store(StoreBatchShipmentRequest $request, ShipmentBatch $batch, ShipmentBatchService $batches): RedirectResponse
    {
        $added = $batches->addShipments($batch, $request->validated('shipments'), $request->user());

        return back()->with('success', $added === 1 ? '1 shipment added to the batch.' : "{$added} shipments added to the batch.");
    }

    public function destroy(ShipmentBatch $batch, Shipment $shipment, ShipmentBatchService $batches): RedirectResponse
    {
        $this->authorize('update', $batch);

        $batches->removeShipment($batch, $shipment, request()->user());

        return back()->with('success', "{$shipment->tracking_number} removed from the batch.");
    }
}
