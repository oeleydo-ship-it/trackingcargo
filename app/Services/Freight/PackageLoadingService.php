<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\LoadUnitStatus;
use App\Models\LoadUnit;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PackageLoadingService
{
    public function __construct(private AuditService $audit) {}

    public function load(ShipmentPackage $package, LoadUnit $unit, User $actor): void
    {
        if ($package->load_unit_id !== null) {
            throw ValidationException::withMessages(['package' => 'This package is already loaded onto a unit; unload it first.']);
        }

        if ($unit->status !== LoadUnitStatus::Building) {
            throw ValidationException::withMessages(['unit' => 'Packages can only be loaded onto a unit that is still building.']);
        }

        DB::transaction(function () use ($package, $unit, $actor): void {
            $package->forceFill(['load_unit_id' => $unit->getKey()])->save();

            $this->recalculate($unit);

            $this->audit->record('package.loaded', $actor, $package, newValues: ['load_unit_id' => $unit->getKey()]);
        });
    }

    public function unload(ShipmentPackage $package, User $actor): void
    {
        $unit = $package->loadUnit;

        if ($unit === null) {
            throw ValidationException::withMessages(['package' => 'This package is not loaded onto any unit.']);
        }

        if ($unit->status !== LoadUnitStatus::Building) {
            throw ValidationException::withMessages(['unit' => 'Packages can only be unloaded from a unit that is still building.']);
        }

        DB::transaction(function () use ($package, $unit, $actor): void {
            $package->forceFill(['load_unit_id' => null])->save();

            $this->recalculate($unit);

            $this->audit->record('package.unloaded', $actor, $package, oldValues: ['load_unit_id' => $unit->getKey()]);
        });
    }

    private function recalculate(LoadUnit $unit): void
    {
        $totals = $unit->packages()->selectRaw('count(*) as package_count, coalesce(sum(weight_kg), 0) as weight_kg')->first();

        $unit->forceFill([
            'package_count' => (int) $totals->package_count,
            'weight_kg' => (float) $totals->weight_kg,
        ])->save();

        $master = $unit->master;
        $masterTotals = $master->loadUnits()->selectRaw('count(*) as unit_count, coalesce(sum(package_count), 0) as package_count, coalesce(sum(weight_kg), 0) as weight_kg')->first();

        $master->forceFill([
            'package_count' => (int) $masterTotals->package_count,
            'weight_kg' => (float) $masterTotals->weight_kg,
        ])->save();
    }
}
