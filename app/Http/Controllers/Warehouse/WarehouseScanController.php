<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehouse;

use App\Enums\WarehouseScanType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreScanRequest;
use App\Models\LoadUnit;
use App\Models\ShipmentPackage;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Warehouse\WarehouseScanService;
use Illuminate\Http\RedirectResponse;

final class WarehouseScanController extends Controller
{
    public function store(StoreScanRequest $request, Warehouse $warehouse, WarehouseScanService $scans): RedirectResponse
    {
        $data = $request->validated();

        $package = ShipmentPackage::query()->where('barcode', $data['package_barcode'])->firstOrFail();

        $toLocation = isset($data['to_location_code'])
            ? WarehouseLocation::query()->where('warehouse_id', $warehouse->getKey())->where('code', $data['to_location_code'])->first()
            : null;

        $loadUnit = isset($data['load_unit_id'])
            ? LoadUnit::query()->find($data['load_unit_id'])
            : null;

        $scan = $scans->scan(
            $warehouse,
            $package,
            WarehouseScanType::from($data['scan_type']),
            $request->user(),
            $data['idempotency_key'],
            $toLocation,
            $loadUnit,
        );

        return back()->with('success', "Package {$package->barcode} {$scan->scan_type->label()}.");
    }
}
