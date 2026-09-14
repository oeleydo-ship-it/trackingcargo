<?php

declare(strict_types=1);

namespace App\Services\Warehouse;

use App\Models\PackageScan;

/**
 * Shared DTO shaping for a scan, reused by the PackageScanned broadcast payload
 * and the scan board's initial Inertia props, so the live feed and the page's
 * first paint always agree on shape.
 */
final readonly class PackageScanPresenter
{
    /** @return array<string, mixed> */
    public static function present(PackageScan $scan): array
    {
        $scan->loadMissing(['package.shipment', 'warehouse', 'fromLocation', 'toLocation', 'scannedBy']);

        return [
            'id' => $scan->getKey(),
            'scan_type' => $scan->scan_type->value,
            'scan_type_label' => $scan->scan_type->label(),
            'package_barcode' => $scan->package->barcode,
            'shipment_tracking_number' => $scan->package->shipment->tracking_number,
            'warehouse_name' => $scan->warehouse->name,
            'from_location_code' => $scan->fromLocation?->code,
            'to_location_code' => $scan->toLocation?->code,
            'scanned_by' => $scan->scannedBy?->name,
            'occurred_at' => $scan->occurred_at->toIso8601String(),
        ];
    }
}
