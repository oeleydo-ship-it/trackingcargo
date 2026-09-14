<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customs;

use App\Enums\InspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\CompleteCustomsInspectionRequest;
use App\Http\Requests\Customs\StoreCustomsInspectionRequest;
use App\Models\CustomsClearance;
use App\Models\CustomsInspection;
use App\Models\Shipment;
use App\Services\Customs\CustomsInspectionService;
use Illuminate\Http\RedirectResponse;

final class CustomsInspectionController extends Controller
{
    public function store(StoreCustomsInspectionRequest $request, Shipment $shipment, CustomsClearance $clearance, CustomsInspectionService $inspections): RedirectResponse
    {
        abort_unless($clearance->shipment_id === $shipment->getKey(), 404);

        $inspections->schedule($clearance, $request->validated(), $request->user());

        return back()->with('success', 'Inspection scheduled.');
    }

    public function complete(CompleteCustomsInspectionRequest $request, Shipment $shipment, CustomsClearance $clearance, CustomsInspection $inspection, CustomsInspectionService $inspections): RedirectResponse
    {
        abort_unless($clearance->shipment_id === $shipment->getKey() && $inspection->customs_clearance_id === $clearance->getKey(), 404);

        $data = $request->validated();

        $inspections->complete($inspection, InspectionStatus::from($data['status']), $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'Inspection completed.');
    }
}
