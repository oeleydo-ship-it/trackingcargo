<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Models\Warehouse;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class WarehouseController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Warehouse::class);

        return Inertia::render('Warehouse/Index', [
            'warehouses' => Warehouse::query()
                ->with('branch:id,name')
                ->withCount('locations')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function show(Warehouse $warehouse): Response
    {
        $this->authorize('view', $warehouse);

        $warehouse->load(['branch:id,name', 'zones.locations' => fn ($query) => $query->orderBy('code')]);

        return Inertia::render('Warehouse/Show', [
            'warehouse' => $warehouse,
        ]);
    }

    public function store(StoreWarehouseRequest $request, WarehouseService $warehouses): RedirectResponse
    {
        $warehouse = $warehouses->create($request->validated(), $request->user());

        return to_route('warehouses.show', $warehouse)->with('success', "Warehouse {$warehouse->code} created.");
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse, WarehouseService $warehouses): RedirectResponse
    {
        $warehouses->update($warehouse, $request->validated(), $request->user());

        return back()->with('success', 'Warehouse updated.');
    }
}
