<?php

declare(strict_types=1);

namespace App\Services\Customs;

use App\Models\CustomsClearance;
use App\Models\CustomsDuty;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

final readonly class CustomsDutyService
{
    private const array FIELDS = ['type', 'description', 'amount', 'currency'];

    public function __construct(private AuditService $audit) {}

    public function assess(CustomsClearance $clearance, array $data, User $actor): CustomsDuty
    {
        return DB::transaction(function () use ($clearance, $data, $actor): CustomsDuty {
            $duty = $clearance->duties()->create([
                'type' => $data['type'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'is_paid' => false,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record('customs-duty.assessed', $actor, $duty, newValues: $duty->only(self::FIELDS));

            return $duty;
        });
    }

    /**
     * Marking an already-paid duty paid again is a no-op, not an error — the
     * boolean flag makes this trivially idempotent without needing its own
     * idempotency_key, unlike the clearance status transitions.
     */
    public function markPaid(CustomsDuty $duty, User $actor): CustomsDuty
    {
        if ($duty->is_paid) {
            return $duty;
        }

        return DB::transaction(function () use ($duty, $actor): CustomsDuty {
            $duty->forceFill(['is_paid' => true, 'paid_at' => now()])->save();

            $this->audit->record('customs-duty.paid', $actor, $duty, newValues: ['is_paid' => true]);

            return $duty;
        });
    }
}
