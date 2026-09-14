<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\LoadPackageRequest;
use App\Models\LoadUnit;
use App\Models\Master;
use App\Models\ShipmentPackage;
use App\Services\Freight\PackageLoadingService;
use Illuminate\Http\RedirectResponse;

final class PackageLoadController extends Controller
{
    public function store(LoadPackageRequest $request, Master $master, LoadUnit $unit, PackageLoadingService $loading): RedirectResponse
    {
        abort_unless($unit->master_id === $master->getKey(), 404);

        $package = ShipmentPackage::query()->findOrFail($request->validated('package_id'));

        $loading->load($package, $unit, $request->user());

        return back()->with('success', 'Package loaded.');
    }

    public function destroy(Master $master, LoadUnit $unit, ShipmentPackage $package, PackageLoadingService $loading): RedirectResponse
    {
        $this->authorize('update', $unit);
        abort_unless($unit->master_id === $master->getKey() && $package->load_unit_id === $unit->getKey(), 404);

        $loading->unload($package, request()->user());

        return back()->with('success', 'Package unloaded.');
    }
}
