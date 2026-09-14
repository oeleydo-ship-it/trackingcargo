<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\BoxSize;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ShipmentPackageService
{
    public function __construct(private VolumetricWeightCalculator $volumetrics) {}

    /**
     * @param  array{weight_kg: float, weight_unit?: string, length?: float, width?: float, height?: float, dimension_unit?: string, description?: string, declared_value?: float, box_size_id?: int}  $data
     */
    public function add(Shipment $shipment, array $data, int $divisor): ShipmentPackage
    {
        return DB::transaction(function () use ($shipment, $data, $divisor): ShipmentPackage {
            $boxSize = $this->resolveBoxSize($data);
            [$weightKg, $volumetricWeightKg, $lengthCm, $widthCm, $heightCm] = $this->normalize($data, $divisor, $boxSize);

            $packageNumber = ((int) $shipment->packages()->max('package_number')) + 1;

            $package = $shipment->packages()->create([
                'box_size_id' => $boxSize?->getKey(),
                'package_number' => $packageNumber,
                'barcode' => sprintf('%s-%02d', $shipment->tracking_number, $packageNumber),
                'description' => $data['description'] ?? null,
                'weight_kg' => $weightKg,
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'volumetric_weight_kg' => $volumetricWeightKg,
                'declared_value' => $data['declared_value'] ?? null,
            ]);

            $this->recalculateSummary($shipment);

            return $package;
        });
    }

    public function update(ShipmentPackage $package, array $data, int $divisor): ShipmentPackage
    {
        return DB::transaction(function () use ($package, $data, $divisor): ShipmentPackage {
            $boxSize = $this->resolveBoxSize($data);
            [$weightKg, $volumetricWeightKg, $lengthCm, $widthCm, $heightCm] = $this->normalize($data, $divisor, $boxSize);

            $package->fill([
                'box_size_id' => $boxSize?->getKey(),
                'description' => $data['description'] ?? null,
                'weight_kg' => $weightKg,
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'volumetric_weight_kg' => $volumetricWeightKg,
                'declared_value' => $data['declared_value'] ?? null,
            ])->save();

            $this->recalculateSummary($package->shipment);

            return $package;
        });
    }

    private function resolveBoxSize(array $data): ?BoxSize
    {
        if (! isset($data['box_size_id'])) {
            return null;
        }

        return BoxSize::query()->findOrFail($data['box_size_id']);
    }

    public function remove(ShipmentPackage $package): void
    {
        DB::transaction(function () use ($package): void {
            $shipment = $package->shipment;

            if ($shipment->packages()->count() <= 1) {
                throw ValidationException::withMessages(['package' => 'A shipment must keep at least one package.']);
            }

            $package->delete();

            $this->recalculateSummary($shipment);
        });
    }

    private function recalculateSummary(Shipment $shipment): void
    {
        $totals = $shipment->packages()->selectRaw('count(*) as package_count, coalesce(sum(weight_kg), 0) as declared_weight_kg, coalesce(sum(volumetric_weight_kg), 0) as volumetric_weight_kg')->first();

        $declaredWeightKg = (float) $totals->declared_weight_kg;
        $volumetricWeightKg = (float) $totals->volumetric_weight_kg;

        $shipment->forceFill([
            'package_count' => (int) $totals->package_count,
            'declared_weight_kg' => $declaredWeightKg,
            'volumetric_weight_kg' => $volumetricWeightKg,
            'chargeable_weight_kg' => $this->volumetrics->chargeableWeightKg($declaredWeightKg, $volumetricWeightKg),
        ])->save();
    }

    /**
     * A box size's dimensions are stored pre-normalized in cm (see
     * `BoxSize`), so they're used directly, without going through
     * `dimension_unit` normalization, whenever the caller didn't already
     * supply an explicit length/width/height of their own — picking a
     * preset size fills the dimensions in rather than replacing a value
     * the caller deliberately set.
     *
     * @return array{0: float, 1: float, 2: ?float, 3: ?float, 4: ?float}
     */
    private function normalize(array $data, int $divisor, ?BoxSize $boxSize): array
    {
        $weightUnit = $data['weight_unit'] ?? 'kg';
        $dimensionUnit = $data['dimension_unit'] ?? 'cm';

        $weightKg = $this->volumetrics->normalizeWeightToKg((float) $data['weight_kg'], $weightUnit);

        $hasDimensions = isset($data['length']) && isset($data['width']) && isset($data['height']);

        if (! $hasDimensions && $boxSize !== null) {
            $lengthCm = (float) $boxSize->length_cm;
            $widthCm = (float) $boxSize->width_cm;
            $heightCm = (float) $boxSize->height_cm;
            $volumetricWeightKg = $this->volumetrics->volumetricWeightKg($lengthCm, $widthCm, $heightCm, $divisor);

            return [$weightKg, $volumetricWeightKg, $lengthCm, $widthCm, $heightCm];
        }

        if (! $hasDimensions) {
            return [$weightKg, 0.0, null, null, null];
        }

        $lengthCm = $this->volumetrics->normalizeDimensionToCm((float) $data['length'], $dimensionUnit);
        $widthCm = $this->volumetrics->normalizeDimensionToCm((float) $data['width'], $dimensionUnit);
        $heightCm = $this->volumetrics->normalizeDimensionToCm((float) $data['height'], $dimensionUnit);
        $volumetricWeightKg = $this->volumetrics->volumetricWeightKg($lengthCm, $widthCm, $heightCm, $divisor);

        return [$weightKg, $volumetricWeightKg, $lengthCm, $widthCm, $heightCm];
    }
}
