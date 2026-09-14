<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDeliveryZoneRequest;
use App\Models\DeliveryZone;
use App\Services\Delivery\DeliveryZoneService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DeliveryZoneController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', DeliveryZone::class);

        return Inertia::render('Delivery/Zones/Index', [
            'zones' => DeliveryZone::query()->with('branch:id,name')->orderBy('code')->get(),
        ]);
    }

    public function store(StoreDeliveryZoneRequest $request, DeliveryZoneService $zones): RedirectResponse
    {
        $zones->create($request->validated(), $request->user());

        return back()->with('success', 'Delivery zone added.');
    }
}
