<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Contracts\AssignmentStrategy;
use App\Enums\DeliveryAssignmentStatus;
use App\Enums\DriverStatus;
use App\Models\DeliveryAssignment;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeliveryAssignmentService
{
    public function __construct(
        private AuditService $audit,
        private AssignmentStrategy $strategy,
    ) {}

    /**
     * A driver_id in $data picks that driver directly (manual assignment).
     * Without one, eligible active drivers in the shipment's branch (and zone,
     * if given, falling back to the whole branch when the zone has nobody
     * free) are handed to the bound AssignmentStrategy — "smart assign".
     */
    public function assign(Shipment $shipment, array $data, User $actor): DeliveryAssignment
    {
        $hasOpenAssignment = $shipment->deliveryAssignments()
            ->whereNotIn('status', [DeliveryAssignmentStatus::Delivered->value, DeliveryAssignmentStatus::Cancelled->value])
            ->exists();

        if ($hasOpenAssignment) {
            throw ValidationException::withMessages(['shipment' => 'This shipment already has an open delivery assignment.']);
        }

        $driver = isset($data['driver_id'])
            ? Driver::query()->findOrFail($data['driver_id'])
            : $this->selectDriver($shipment, $data['zone_id'] ?? null);

        if ($driver === null) {
            throw ValidationException::withMessages(['driver_id' => 'No active driver is available to assign.']);
        }

        return DB::transaction(function () use ($shipment, $data, $driver, $actor): DeliveryAssignment {
            $assignment = $shipment->deliveryAssignments()->create([
                'branch_id' => $shipment->branch_id,
                'driver_id' => $driver->getKey(),
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'zone_id' => $data['zone_id'] ?? $driver->zone_id,
                'status' => DeliveryAssignmentStatus::Assigned,
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
                'scheduled_date' => $data['scheduled_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->record('delivery-assignment.created', $actor, $assignment, newValues: [
                'driver_id' => $driver->getKey(),
                'vehicle_id' => $assignment->vehicle_id,
            ]);

            return $assignment;
        });
    }

    private function selectDriver(Shipment $shipment, ?int $zoneId): ?Driver
    {
        $query = Driver::query()->where('branch_id', $shipment->branch_id)->where('status', DriverStatus::Active->value);

        if ($zoneId !== null) {
            $zoned = (clone $query)->where('zone_id', $zoneId)->get();

            if ($zoned->isNotEmpty()) {
                return $this->strategy->selectDriver($zoned);
            }
        }

        return $this->strategy->selectDriver($query->get());
    }
}
