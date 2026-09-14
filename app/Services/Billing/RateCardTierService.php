<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\RateCard;
use App\Models\RateCardTier;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RateCardTierService
{
    public function __construct(private AuditService $audit) {}

    public function create(RateCard $rateCard, array $data, User $actor): RateCardTier
    {
        $overlapping = $rateCard->tiers()
            ->where('min_weight_kg', '<', $data['max_weight_kg'] ?? PHP_FLOAT_MAX)
            ->where(fn ($query) => $query->whereNull('max_weight_kg')->orWhere('max_weight_kg', '>', $data['min_weight_kg']))
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages(['min_weight_kg' => 'This weight range overlaps an existing tier on the rate card.']);
        }

        return DB::transaction(function () use ($rateCard, $data, $actor): RateCardTier {
            $tier = $rateCard->tiers()->create([
                'min_weight_kg' => $data['min_weight_kg'],
                'max_weight_kg' => $data['max_weight_kg'] ?? null,
                'price_per_kg' => $data['price_per_kg'],
            ]);

            $this->audit->record('rate-card-tier.created', $actor, $tier, newValues: $tier->only(['min_weight_kg', 'max_weight_kg', 'price_per_kg']));

            return $tier;
        });
    }
}
