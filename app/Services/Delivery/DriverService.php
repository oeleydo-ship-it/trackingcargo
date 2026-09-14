<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class DriverService
{
    private const array FIELDS = ['branch_id', 'zone_id', 'user_id', 'license_number', 'phone'];

    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): Driver
    {
        return DB::transaction(function () use ($data, $actor): Driver {
            $driver = Driver::query()->create([
                ...Arr::only($data, self::FIELDS),
                'status' => DriverStatus::Active,
            ]);

            $this->audit->record('driver.created', $actor, $driver, newValues: $driver->only(self::FIELDS));

            return $driver;
        });
    }

    public function update(Driver $driver, array $data, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $data, $actor): Driver {
            $old = $driver->only([...self::FIELDS, 'status']);

            $driver->forceFill(Arr::only($data, [...self::FIELDS, 'status']))->save();

            $this->audit->record('driver.updated', $actor, $driver, oldValues: $old, newValues: $driver->only([...self::FIELDS, 'status']));

            return $driver;
        });
    }
}
