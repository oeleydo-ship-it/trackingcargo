<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreShipmentPackageRequest;
use App\Http\Requests\Shipments\UpdateShipmentPackageRequest;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Services\Shipments\ShipmentPackageService;
use Illuminate\Http\RedirectResponse;

final class ShipmentPackageController extends Controller
{
    public function store(StoreShipmentPackageRequest $request, Shipment $shipment, ShipmentPackageService $packages): RedirectResponse
    {
        $packages->add($shipment, $request->validated(), $this->divisor($shipment));

        return back()->with('success', 'Package added.');
    }

    public function update(UpdateShipmentPackageRequest $request, Shipment $shipment, ShipmentPackage $package, ShipmentPackageService $packages): RedirectResponse
    {
        abort_unless($package->shipment_id === $shipment->getKey(), 404);

        $packages->update($package, $request->validated(), $this->divisor($shipment));

        return back()->with('success', 'Package updated.');
    }

    public function destroy(Shipment $shipment, ShipmentPackage $package, ShipmentPackageService $packages): RedirectResponse
    {
        $this->authorize('update', $shipment);
        abort_unless($package->shipment_id === $shipment->getKey(), 404);

        $packages->remove($package);

        return back()->with('success', 'Package removed.');
    }

    private function divisor(Shipment $shipment): int
    {
        return Company::query()->findOrFail($shipment->company_id)->volumetric_divisor;
    }
}
