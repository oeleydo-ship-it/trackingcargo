<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\RateCard;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class RateCardService
{
    private const array FIELDS = ['branch_id', 'customer_id', 'name', 'mode', 'currency', 'base_fee', 'min_charge'];

    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): RateCard
    {
        return DB::transaction(function () use ($data, $actor): RateCard {
            $rateCard = RateCard::query()->create([
                ...Arr::only($data, self::FIELDS),
                'is_active' => true,
            ]);

            $this->audit->record('rate-card.created', $actor, $rateCard, newValues: $rateCard->only(self::FIELDS));

            return $rateCard;
        });
    }
}
