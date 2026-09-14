<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\DeliveryZone;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

final readonly class DeliveryZoneService
{
    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): DeliveryZone
    {
        return DB::transaction(function () use ($data, $actor): DeliveryZone {
            $zone = DeliveryZone::query()->create([
                'branch_id' => $data['branch_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            $this->audit->record('delivery-zone.created', $actor, $zone, newValues: $zone->only(['code', 'name']));

            return $zone;
        });
    }
}
