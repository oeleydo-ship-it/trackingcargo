<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreVehicleRequest;
use App\Http\Requests\Delivery\UpdateVehicleRequest;
use App\Models\Vehicle;
use App\Services\Delivery\VehicleService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class VehicleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Vehicle::class);

        return Inertia::render('Delivery/Vehicles/Index', [
            'vehicles' => Vehicle::query()
                ->with('branch:id,name')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function store(StoreVehicleRequest $request, VehicleService $vehicles): RedirectResponse
    {
        $vehicles->create($request->validated(), $request->user());

        return back()->with('success', 'Vehicle added.');
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle, VehicleService $vehicles): RedirectResponse
    {
        $vehicles->update($vehicle, $request->validated(), $request->user());

        return back()->with('success', 'Vehicle updated.');
    }
}
