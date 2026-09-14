<?php

declare(strict_types=1);

namespace App\Services\Customs;

use App\Enums\InspectionStatus;
use App\Models\CustomsClearance;
use App\Models\CustomsInspection;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomsInspectionService
{
    public function __construct(private AuditService $audit) {}

    public function schedule(CustomsClearance $clearance, array $data, User $actor): CustomsInspection
    {
        return DB::transaction(function () use ($clearance, $data, $actor): CustomsInspection {
            $inspection = $clearance->inspections()->create([
                'type' => $data['type'],
                'status' => InspectionStatus::Scheduled,
                'scheduled_at' => $data['scheduled_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record('customs-inspection.scheduled', $actor, $inspection, newValues: $inspection->only(['type', 'scheduled_at']));

            return $inspection;
        });
    }

    public function complete(CustomsInspection $inspection, InspectionStatus $result, User $actor, ?string $notes = null): CustomsInspection
    {
        if ($inspection->status !== InspectionStatus::Scheduled) {
            throw ValidationException::withMessages(['status' => 'Only a scheduled inspection can be completed.']);
        }

        return DB::transaction(function () use ($inspection, $result, $actor, $notes): CustomsInspection {
            $inspection->forceFill([
                'status' => $result,
                'completed_at' => now(),
                'notes' => $notes ?? $inspection->notes,
            ])->save();

            $this->audit->record('customs-inspection.completed', $actor, $inspection, newValues: ['status' => $result->value]);

            return $inspection;
        });
    }
}
