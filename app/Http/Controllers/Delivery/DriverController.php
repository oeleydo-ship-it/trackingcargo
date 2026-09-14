<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDriverRequest;
use App\Http\Requests\Delivery\UpdateDriverRequest;
use App\Models\Driver;
use App\Services\Delivery\DriverService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DriverController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Driver::class);

        return Inertia::render('Delivery/Drivers/Index', [
            'drivers' => Driver::query()
                ->with(['user:id,name,email', 'branch:id,name', 'zone:id,name'])
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function store(StoreDriverRequest $request, DriverService $drivers): RedirectResponse
    {
        $drivers->create($request->validated(), $request->user());

        return back()->with('success', 'Driver added.');
    }

    public function update(UpdateDriverRequest $request, Driver $driver, DriverService $drivers): RedirectResponse
    {
        $drivers->update($driver, $request->validated(), $request->user());

        return back()->with('success', 'Driver updated.');
    }
}
