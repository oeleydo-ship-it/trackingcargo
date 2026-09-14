<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customs;

use App\Enums\CustomsClearanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreCustomsClearanceRequest;
use App\Models\CustomsClearance;
use App\Models\Shipment;
use App\Services\Customs\CustomsClearanceService;
use App\Services\Customs\CustomsClearanceTransitionMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomsClearanceController extends Controller
{
    /**
     * The "operations board" for customs: every non-terminal clearance
     * across the company (branch-scoped the same way ShipmentController's
     * index already is), so a customs officer has one place to see
     * everything waiting on them instead of finding each shipment
     * individually. Terminal clearances (cleared/rejected) are excluded —
     * this is a queue, not an archive.
     */
    public function queue(Request $request): Response
    {
        $this->authorize('viewAny', CustomsClearance::class);

        $user = $request->user();

        return Inertia::render('CustomsClearances/Queue', [
            'clearances' => CustomsClearance::query()
                ->with(['shipment:id,tracking_number,destination_country_code,destination_city', 'branch:id,name'])
                ->whereNotIn('status', [CustomsClearanceStatus::Cleared->value, CustomsClearanceStatus::Rejected->value])
                ->when(
                    $user?->branch_id !== null && ! $user->hasPermission('customs.manage'),
                    fn ($query) => $query->where('branch_id', $user->branch_id),
                )
                ->orderBy('submitted_at')
                ->limit(200)
                ->get(),
        ]);
    }

    public function show(Shipment $shipment, CustomsClearance $clearance): Response
    {
        $this->authorize('view', $clearance);
        abort_unless($clearance->shipment_id === $shipment->getKey(), 404);

        $clearance->load(['duties.createdBy:id,name', 'inspections.createdBy:id,name', 'documents.uploadedBy:id,name']);

        return Inertia::render('CustomsClearances/Show', [
            'shipment' => ['id' => $shipment->getKey(), 'tracking_number' => $shipment->tracking_number, 'status' => $shipment->status],
            'clearance' => $clearance,
            'allowedTransitions' => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                CustomsClearanceTransitionMap::allowedFrom($clearance->status),
            ),
        ]);
    }

    public function store(StoreCustomsClearanceRequest $request, Shipment $shipment, CustomsClearanceService $clearances): RedirectResponse
    {
        $clearance = $clearances->open($shipment, $request->validated(), $request->user());

        return to_route('shipments.customsClearances.show', [$shipment, $clearance])->with('success', 'Customs clearance opened.');
    }
}
