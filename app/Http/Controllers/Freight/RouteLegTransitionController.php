<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Enums\RouteLegStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\TransitionRouteLegRequest;
use App\Models\RouteLeg;
use App\Models\Shipment;
use App\Services\Freight\RouteLegTransitionService;
use Illuminate\Http\RedirectResponse;

final class RouteLegTransitionController extends Controller
{
    public function store(TransitionRouteLegRequest $request, Shipment $shipment, RouteLeg $leg, RouteLegTransitionService $transitions): RedirectResponse
    {
        abort_unless($leg->shipment_id === $shipment->getKey(), 404);

        $transitions->transition($leg, RouteLegStatus::from($request->validated('status')), $request->user());

        return back()->with('success', 'Route leg status updated.');
    }
}
