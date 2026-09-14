<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\DeliveryAssignmentStatus;
use App\Enums\ShipmentStatusRole;
use App\Models\DeliveryAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeliveryAssignmentTransitionService
{
    public function __construct(
        private AuditService $audit,
        private ShipmentTransitionService $shipmentTransitions,
    ) {}

    public function transition(DeliveryAssignment $assignment, DeliveryAssignmentStatus $to, User $actor): DeliveryAssignment
    {
        $from = $assignment->status;

        if (! DeliveryAssignmentTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition a delivery assignment from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        return DB::transaction(function () use ($assignment, $from, $to, $actor): DeliveryAssignment {
            $assignment->forceFill(['status' => $to])->save();

            $this->audit->record('delivery-assignment.status-changed', $actor, $assignment, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

            if ($to === DeliveryAssignmentStatus::OutForDelivery && ! $assignment->shipment->hasStatusRole(ShipmentStatusRole::OutForDelivery)) {
                $this->shipmentTransitions->transitionToRole($assignment->shipment, ShipmentStatusRole::OutForDelivery, $actor);
            }

            return $assignment;
        });
    }
}
