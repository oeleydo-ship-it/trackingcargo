<?php

declare(strict_types=1);

namespace App\Services\Warehouse;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class WarehouseService
{
    private const array FIELDS = ['branch_id', 'code', 'name', 'city', 'country_code'];

    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): Warehouse
    {
        return DB::transaction(function () use ($data, $actor): Warehouse {
            $warehouse = Warehouse::query()->create([
                ...Arr::only($data, self::FIELDS),
                'is_active' => true,
            ]);

            $this->audit->record('warehouse.created', $actor, $warehouse, newValues: $warehouse->only(self::FIELDS));

            return $warehouse;
        });
    }

    public function update(Warehouse $warehouse, array $data, User $actor): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data, $actor): Warehouse {
            $old = $warehouse->only(self::FIELDS);

            $warehouse->forceFill(Arr::only($data, self::FIELDS))->save();

            $this->audit->record('warehouse.updated', $actor, $warehouse, oldValues: $old, newValues: $warehouse->only(self::FIELDS));

            return $warehouse;
        });
    }
}
