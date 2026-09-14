<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseLocationRequest;
use App\Models\Warehouse;
use App\Models\WarehouseZone;
use App\Services\Warehouse\WarehouseLocationService;
use Illuminate\Http\RedirectResponse;

final class WarehouseLocationController extends Controller
{
    public function store(StoreWarehouseLocationRequest $request, Warehouse $warehouse, WarehouseZone $zone, WarehouseLocationService $locations): RedirectResponse
    {
        abort_unless($zone->warehouse_id === $warehouse->getKey(), 404);

        $locations->create($zone, $request->validated(), $request->user());

        return back()->with('success', 'Location added.');
    }
}
