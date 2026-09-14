<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class VehicleService
{
    private const array FIELDS = ['branch_id', 'registration_number', 'type', 'capacity_kg'];

    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): Vehicle
    {
        return DB::transaction(function () use ($data, $actor): Vehicle {
            $vehicle = Vehicle::query()->create([
                ...Arr::only($data, self::FIELDS),
                'status' => VehicleStatus::Active,
            ]);

            $this->audit->record('vehicle.created', $actor, $vehicle, newValues: $vehicle->only(self::FIELDS));

            return $vehicle;
        });
    }

    public function update(Vehicle $vehicle, array $data, User $actor): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor): Vehicle {
            $old = $vehicle->only([...self::FIELDS, 'status']);

            $vehicle->forceFill(Arr::only($data, [...self::FIELDS, 'status']))->save();

            $this->audit->record('vehicle.updated', $actor, $vehicle, oldValues: $old, newValues: $vehicle->only([...self::FIELDS, 'status']));

            return $vehicle;
        });
    }
}
