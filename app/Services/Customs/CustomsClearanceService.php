<?php

declare(strict_types=1);

namespace App\Services\Customs;

use App\Enums\CustomsClearanceStatus;
use App\Enums\ShipmentStatusRole;
use App\Models\CustomsClearance;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomsClearanceService
{
    private const array FIELDS = ['declaration_number', 'customs_office', 'notes'];

    public function __construct(
        private AuditService $audit,
        private ShipmentTransitionService $shipmentTransitions,
    ) {}

    /**
     * Opening a clearance is where "customs and shipment transitions agree" is
     * enforced structurally: the shipment is moved to `at_customs` through the
     * normal ShipmentTransitionService, so a clearance simply cannot be opened
     * on a shipment that isn't allowed to be at customs right now.
     */
    public function open(Shipment $shipment, array $data, User $actor): CustomsClearance
    {
        $hasOpenClearance = $shipment->customsClearances()
            ->whereNotIn('status', [CustomsClearanceStatus::Cleared->value, CustomsClearanceStatus::Rejected->value])
            ->exists();

        if ($hasOpenClearance) {
            throw ValidationException::withMessages(['shipment' => 'This shipment already has an open customs clearance.']);
        }

        return DB::transaction(function () use ($shipment, $data, $actor): CustomsClearance {
            if (! $shipment->hasStatusRole(ShipmentStatusRole::AtCustoms)) {
                $this->shipmentTransitions->transitionToRole($shipment, ShipmentStatusRole::AtCustoms, $actor);
            }

            $clearance = $shipment->customsClearances()->create([
                ...Arr::only($data, self::FIELDS),
                'branch_id' => $shipment->branch_id,
                'status' => CustomsClearanceStatus::Pending,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ]);

            $this->audit->record('customs-clearance.opened', $actor, $clearance, newValues: $clearance->only([...self::FIELDS, 'status']));

            return $clearance;
        });
    }
}
