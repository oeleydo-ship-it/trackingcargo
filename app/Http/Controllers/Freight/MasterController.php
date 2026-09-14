<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\StoreMasterRequest;
use App\Models\Master;
use App\Services\Freight\MasterService;
use App\Services\Freight\MasterTransitionMap;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class MasterController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Master::class);

        return Inertia::render('Freight/Masters/Index', [
            'masters' => Master::query()
                ->with('branch:id,name')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function show(Master $master): Response
    {
        $this->authorize('view', $master);

        $master->load(['branch:id,name', 'loadUnits.packages.shipment:id,tracking_number', 'manifests.generatedBy:id,name']);

        return Inertia::render('Freight/Masters/Show', [
            'master' => $master,
            'allowedTransitions' => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                MasterTransitionMap::allowedFrom($master->status),
            ),
        ]);
    }

    public function store(StoreMasterRequest $request, MasterService $masters): RedirectResponse
    {
        $master = $masters->create($request->validated(), $request->user());

        return to_route('freight.masters.show', $master)->with('success', "Master {$master->master_number} created.");
    }
}
