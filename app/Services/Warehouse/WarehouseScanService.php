<?php

declare(strict_types=1);

namespace App\Services\Warehouse;

use App\Enums\WarehouseScanType;
use App\Events\PackageScanned;
use App\Models\LoadUnit;
use App\Models\PackageScan;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Audit\AuditService;
use App\Services\Freight\PackageLoadingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent scan intake: a retried request carrying the same idempotency_key
 * never re-applies a position change. The fast path checks for an existing
 * scan before doing any work; the unique (company_id, idempotency_key)
 * constraint is the actual safety net under a genuine concurrent race,
 * mirroring NumberSequenceService's own "insert, catch 23000, re-fetch" shape.
 */
final readonly class WarehouseScanService
{
    public function __construct(
        private AuditService $audit,
        private PackageLoadingService $loading,
    ) {}

    public function scan(
        Warehouse $warehouse,
        ShipmentPackage $package,
        WarehouseScanType $type,
        User $actor,
        string $idempotencyKey,
        ?WarehouseLocation $toLocation = null,
        ?LoadUnit $loadUnit = null,
    ): PackageScan {
        $existing = PackageScan::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($toLocation !== null && $toLocation->warehouse_id !== $warehouse->getKey()) {
            throw ValidationException::withMessages(['to_location_id' => 'The location does not belong to this warehouse.']);
        }

        try {
            $scan = DB::transaction(function () use ($warehouse, $package, $type, $actor, $idempotencyKey, $toLocation, $loadUnit): PackageScan {
                $fromLocation = $package->warehouseLocation;

                match ($type) {
                    WarehouseScanType::Receive => $this->applyReceive($package, $toLocation),
                    WarehouseScanType::Sort => $this->applySort($package, $toLocation),
                    WarehouseScanType::Load => $this->applyLoad($package, $loadUnit, $actor),
                    WarehouseScanType::Unload => $this->applyUnload($package, $toLocation, $actor),
                    WarehouseScanType::Dispatch => $this->applyDispatch($package),
                };

                $scan = PackageScan::query()->create([
                    'warehouse_id' => $warehouse->getKey(),
                    'shipment_package_id' => $package->getKey(),
                    'scan_type' => $type,
                    'from_location_id' => $fromLocation?->getKey(),
                    'to_location_id' => $toLocation?->getKey(),
                    'load_unit_id' => $loadUnit?->getKey(),
                    'scanned_by' => $actor->getKey(),
                    'branch_id' => $actor->branch_id,
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => now(),
                ]);

                $this->audit->record("package.scan.{$type->value}", $actor, $package, newValues: [
                    'scan_type' => $type->value,
                    'to_location_id' => $toLocation?->getKey(),
                    'load_unit_id' => $loadUnit?->getKey(),
                ]);

                return $scan;
            });
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() !== 23000) {
                throw $exception;
            }

            return PackageScan::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        event(new PackageScanned($scan));

        return $scan;
    }

    private function applyReceive(ShipmentPackage $package, ?WarehouseLocation $toLocation): void
    {
        if ($toLocation === null) {
            throw ValidationException::withMessages(['to_location_id' => 'A location is required to receive a package.']);
        }

        if ($package->warehouse_location_id !== null) {
            throw ValidationException::withMessages(['package' => 'This package has already been received; use sort to move it.']);
        }

        if ($package->load_unit_id !== null) {
            throw ValidationException::withMessages(['package' => 'This package is loaded onto a unit; unload it first.']);
        }

        $package->forceFill(['warehouse_location_id' => $toLocation->getKey()])->save();

        $this->recalculate($toLocation);
    }

    private function applySort(ShipmentPackage $package, ?WarehouseLocation $toLocation): void
    {
        if ($toLocation === null) {
            throw ValidationException::withMessages(['to_location_id' => 'A destination location is required to sort a package.']);
        }

        $fromLocation = $package->warehouseLocation;

        if ($fromLocation === null) {
            throw ValidationException::withMessages(['package' => 'This package must be received before it can be sorted.']);
        }

        if ($fromLocation->getKey() === $toLocation->getKey()) {
            throw ValidationException::withMessages(['to_location_id' => 'The package is already at this location.']);
        }

        $package->forceFill(['warehouse_location_id' => $toLocation->getKey()])->save();

        $this->recalculate($fromLocation);
        $this->recalculate($toLocation);
    }

    private function applyLoad(ShipmentPackage $package, ?LoadUnit $loadUnit, User $actor): void
    {
        if ($loadUnit === null) {
            throw ValidationException::withMessages(['load_unit_id' => 'A load unit is required to load a package.']);
        }

        if ($package->warehouse_location_id === null) {
            throw ValidationException::withMessages(['package' => 'This package must be at a warehouse location before it can be loaded.']);
        }

        $fromLocation = $package->warehouseLocation;

        $this->loading->load($package, $loadUnit, $actor);
        $package->forceFill(['warehouse_location_id' => null])->save();

        $this->recalculate($fromLocation);
    }

    private function applyUnload(ShipmentPackage $package, ?WarehouseLocation $toLocation, User $actor): void
    {
        if ($toLocation === null) {
            throw ValidationException::withMessages(['to_location_id' => 'A location is required to unload a package.']);
        }

        if ($package->load_unit_id === null) {
            throw ValidationException::withMessages(['package' => 'This package is not loaded onto any unit.']);
        }

        $this->loading->unload($package, $actor);
        $package->forceFill(['warehouse_location_id' => $toLocation->getKey()])->save();

        $this->recalculate($toLocation);
    }

    private function applyDispatch(ShipmentPackage $package): void
    {
        if ($package->warehouse_location_id === null) {
            throw ValidationException::withMessages(['package' => 'This package must be at a warehouse location before it can be dispatched.']);
        }

        $fromLocation = $package->warehouseLocation;

        $package->forceFill(['warehouse_location_id' => null])->save();

        $this->recalculate($fromLocation);
    }

    private function recalculate(WarehouseLocation $location): void
    {
        $location->forceFill(['package_count' => $location->packages()->count()])->save();
    }
}
