<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreCustomsDutyRequest;
use App\Models\CustomsClearance;
use App\Models\CustomsDuty;
use App\Models\Shipment;
use App\Services\Customs\CustomsDutyService;
use Illuminate\Http\RedirectResponse;

final class CustomsDutyController extends Controller
{
    public function store(StoreCustomsDutyRequest $request, Shipment $shipment, CustomsClearance $clearance, CustomsDutyService $duties): RedirectResponse
    {
        abort_unless($clearance->shipment_id === $shipment->getKey(), 404);

        $duties->assess($clearance, $request->validated(), $request->user());

        return back()->with('success', 'Duty/tax recorded.');
    }

    public function pay(Shipment $shipment, CustomsClearance $clearance, CustomsDuty $duty, CustomsDutyService $duties): RedirectResponse
    {
        $this->authorize('update', $clearance);
        abort_unless($clearance->shipment_id === $shipment->getKey() && $duty->customs_clearance_id === $clearance->getKey(), 404);

        $duties->markPaid($duty, request()->user());

        return back()->with('success', 'Duty marked paid.');
    }
}
