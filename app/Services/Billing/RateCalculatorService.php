<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\RateCard;
use Illuminate\Validation\ValidationException;

/**
 * Pure calculation, no persistence — deliberately side-effect-free so it's
 * unit-testable without a database. Money arithmetic is plain PHP float
 * math rounded to 2dp at the end (matching the rest of this codebase's
 * convention — see VolumetricWeightCalculator, Phase 3 — rather than
 * introducing bcmath/integer-cents as a new pattern this far into the
 * project for a single phase).
 */
final readonly class RateCalculatorService
{
    /** @return array{amount: float, currency: string} */
    public function calculate(RateCard $rateCard, float $weightKg): array
    {
        if ($weightKg <= 0) {
            throw ValidationException::withMessages(['weight_kg' => 'Weight must be greater than zero to calculate a rate.']);
        }

        $tier = $rateCard->tiers->first(
            fn ($tier) => $weightKg >= (float) $tier->min_weight_kg
                && ($tier->max_weight_kg === null || $weightKg < (float) $tier->max_weight_kg),
        );

        if ($tier === null) {
            throw ValidationException::withMessages(['weight_kg' => 'No rate tier on this rate card covers this weight.']);
        }

        $amount = round((float) $rateCard->base_fee + (float) $tier->price_per_kg * $weightKg, 2);
        $amount = max($amount, (float) $rateCard->min_charge);

        return ['amount' => $amount, 'currency' => $rateCard->currency];
    }
}
