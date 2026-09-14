<?php

declare(strict_types=1);

namespace App\Services\Warehouse;

use App\Models\User;
use App\Models\WarehouseLocation;
use App\Models\WarehouseZone;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

final readonly class WarehouseLocationService
{
    public function __construct(private AuditService $audit) {}

    public function create(WarehouseZone $zone, array $data, User $actor): WarehouseLocation
    {
        return DB::transaction(function () use ($zone, $data, $actor): WarehouseLocation {
            $location = $zone->locations()->create([
                'warehouse_id' => $zone->warehouse_id,
                'code' => $data['code'],
                'package_count' => 0,
                'is_active' => true,
            ]);

            $this->audit->record('warehouse-location.created', $actor, $location, newValues: ['code' => $location->code, 'zone_id' => $zone->getKey()]);

            return $location;
        });
    }
}
