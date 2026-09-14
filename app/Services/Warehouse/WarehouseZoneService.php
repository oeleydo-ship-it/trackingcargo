<?php

declare(strict_types=1);

namespace App\Services\Warehouse;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseZone;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

final readonly class WarehouseZoneService
{
    public function __construct(private AuditService $audit) {}

    public function create(Warehouse $warehouse, array $data, User $actor): WarehouseZone
    {
        return DB::transaction(function () use ($warehouse, $data, $actor): WarehouseZone {
            $zone = $warehouse->zones()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'is_active' => true,
            ]);

            $this->audit->record('warehouse-zone.created', $actor, $zone, newValues: $zone->only(['code', 'name', 'type']));

            return $zone;
        });
    }
}
