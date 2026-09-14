<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\StoreRouteLegRequest;
use App\Models\RouteLeg;
use App\Models\Shipment;
use App\Services\Freight\RouteLegService;
use Illuminate\Http\RedirectResponse;

final class RouteLegController extends Controller
{
    public function store(StoreRouteLegRequest $request, Shipment $shipment, RouteLegService $legs): RedirectResponse
    {
        $legs->add($shipment, $request->validated(), $request->user());

        return back()->with('success', 'Route leg added.');
    }

    public function destroy(Shipment $shipment, RouteLeg $leg, RouteLegService $legs): RedirectResponse
    {
        $this->authorize('update', $shipment);
        abort_unless($leg->shipment_id === $shipment->getKey(), 404);

        $legs->remove($leg, request()->user());

        return back()->with('success', 'Route leg removed.');
    }
}
