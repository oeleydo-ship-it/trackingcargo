<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\Warehouse\PackageScanPresenter;
use Inertia\Inertia;
use Inertia\Response;

final class ScanBoardController extends Controller
{
    public function show(Warehouse $warehouse): Response
    {
        $this->authorize('view', $warehouse);

        $warehouse->load(['zones' => fn ($query) => $query->orderBy('code')]);

        return Inertia::render('Warehouse/ScanBoard', [
            'warehouse' => $warehouse,
            'recentScans' => $warehouse->scans()
                ->with(['package.shipment:id,tracking_number', 'fromLocation:id,code', 'toLocation:id,code', 'scannedBy:id,name'])
                ->limit(50)
                ->get()
                ->map(PackageScanPresenter::present(...))
                ->values(),
        ]);
    }
}
