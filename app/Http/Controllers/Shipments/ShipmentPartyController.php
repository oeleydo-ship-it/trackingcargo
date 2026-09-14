<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\UpdateShipmentPartyRequest;
use App\Models\Shipment;
use App\Models\ShipmentParty;
use App\Services\Shipments\ShipmentService;
use Illuminate\Http\RedirectResponse;

final class ShipmentPartyController extends Controller
{
    public function update(UpdateShipmentPartyRequest $request, Shipment $shipment, ShipmentParty $party, ShipmentService $shipments): RedirectResponse
    {
        abort_unless($party->shipment_id === $shipment->getKey(), 404);

        $shipments->updateParty($party, $request->validated(), $request->user());

        return back()->with('success', 'Party updated.');
    }
}
