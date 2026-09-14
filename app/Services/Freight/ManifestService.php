<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\MasterStatus;
use App\Models\Manifest;
use App\Models\Master;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManifestService
{
    public function __construct(
        private NumberSequenceService $sequences,
        private AuditService $audit,
    ) {}

    public function generate(Master $master, User $actor): Manifest
    {
        if ($master->status === MasterStatus::Open) {
            throw ValidationException::withMessages(['status' => 'Close the master for loading before generating a manifest.']);
        }

        return DB::transaction(function () use ($master, $actor): Manifest {
            // Reusing NumberSequenceService with the master's own id as the "period"
            // gives each master an independent, lock-safe version counter without a
            // separate table — the same primitive that already backs customer and
            // shipment numbering.
            $version = $this->sequences->next('manifest', $master->branch_id, (string) $master->getKey());

            $manifest = Manifest::query()->create([
                'master_id' => $master->getKey(),
                'generated_by' => $actor->getKey(),
                'manifest_number' => sprintf('%s-MAN%02d', $master->master_number, $version),
                'version' => $version,
                'snapshot' => $this->buildSnapshot($master),
            ]);

            $this->audit->record('manifest.generated', $actor, $manifest, newValues: ['manifest_number' => $manifest->manifest_number, 'version' => $version]);

            return $manifest;
        });
    }

    private function buildSnapshot(Master $master): array
    {
        $master->load(['loadUnits.packages.shipment']);

        return [
            'master_number' => $master->master_number,
            'mode' => $master->mode->value,
            'conveyance' => $master->conveyanceLabel(),
            'origin' => $master->mode->value === 'air' ? $master->origin_airport : $master->origin_port,
            'destination' => $master->mode->value === 'air' ? $master->destination_airport : $master->destination_port,
            'status' => $master->status->value,
            'package_count' => $master->package_count,
            'weight_kg' => (float) $master->weight_kg,
            'generated_at' => now()->toIso8601String(),
            'load_units' => $master->loadUnits->map(fn ($unit): array => [
                'type' => $unit->type->value,
                'unit_number' => $unit->unit_number,
                'seal_number' => $unit->seal_number,
                'package_count' => $unit->package_count,
                'weight_kg' => (float) $unit->weight_kg,
                'packages' => $unit->packages->map(fn ($package): array => [
                    'barcode' => $package->barcode,
                    'shipment_tracking_number' => $package->shipment->tracking_number,
                    'description' => $package->description,
                    'weight_kg' => (float) $package->weight_kg,
                ])->all(),
            ])->all(),
        ];
    }
}
