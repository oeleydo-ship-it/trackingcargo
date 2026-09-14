<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use InvalidArgumentException;

/**
 * Pure value-object-style formulas. No database access, no side effects:
 * normalizes any input unit to the base units the rest of the system stores
 * (centimeters, kilograms) and applies the company's volumetric divisor.
 */
final class VolumetricWeightCalculator
{
    private const float CM_PER_INCH = 2.54;

    private const float KG_PER_POUND = 0.45359237;

    public function normalizeDimensionToCm(float $value, string $unit): float
    {
        return match ($unit) {
            'cm' => $value,
            'in' => round($value * self::CM_PER_INCH, 4),
            default => throw new InvalidArgumentException("Unsupported dimension unit [{$unit}]."),
        };
    }

    public function normalizeWeightToKg(float $value, string $unit): float
    {
        return match ($unit) {
            'kg' => $value,
            'lb' => round($value * self::KG_PER_POUND, 4),
            default => throw new InvalidArgumentException("Unsupported weight unit [{$unit}]."),
        };
    }

    public function volumetricWeightKg(float $lengthCm, float $widthCm, float $heightCm, int $divisor): float
    {
        if ($divisor < 1) {
            throw new InvalidArgumentException('The volumetric divisor must be a positive integer.');
        }

        return round(($lengthCm * $widthCm * $heightCm) / $divisor, 3);
    }

    public function chargeableWeightKg(float $actualKg, float $volumetricKg): float
    {
        return max($actualKg, $volumetricKg);
    }
}
