<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\StoreLoadUnitRequest;
use App\Models\Master;
use App\Services\Freight\LoadUnitService;
use Illuminate\Http\RedirectResponse;

final class LoadUnitController extends Controller
{
    public function store(StoreLoadUnitRequest $request, Master $master, LoadUnitService $loadUnits): RedirectResponse
    {
        $loadUnits->create($master, $request->validated(), $request->user());

        return back()->with('success', 'Load unit added.');
    }
}
