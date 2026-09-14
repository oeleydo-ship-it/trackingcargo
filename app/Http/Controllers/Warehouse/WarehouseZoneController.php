<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseZoneRequest;
use App\Models\Warehouse;
use App\Services\Warehouse\WarehouseZoneService;
use Illuminate\Http\RedirectResponse;

final class WarehouseZoneController extends Controller
{
    public function store(StoreWarehouseZoneRequest $request, Warehouse $warehouse, WarehouseZoneService $zones): RedirectResponse
    {
        $zones->create($warehouse, $request->validated(), $request->user());

        return back()->with('success', 'Zone added.');
    }
}
