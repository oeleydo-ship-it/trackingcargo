<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\RouteLegStatus;
use App\Models\RouteLeg;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class RouteLegService
{
    private const array FIELDS = ['master_id', 'mode', 'origin_location', 'destination_location', 'scheduled_departure_at', 'scheduled_arrival_at'];

    public function __construct(private AuditService $audit) {}

    public function add(Shipment $shipment, array $data, User $actor): RouteLeg
    {
        return DB::transaction(function () use ($shipment, $data, $actor): RouteLeg {
            $sequence = ((int) $shipment->routeLegs()->max('sequence')) + 1;

            $leg = $shipment->routeLegs()->create([
                ...Arr::only($data, self::FIELDS),
                'sequence' => $sequence,
                'status' => RouteLegStatus::Planned,
            ]);

            $this->audit->record('route-leg.created', $actor, $leg, newValues: [...$leg->only(self::FIELDS), 'sequence' => $sequence]);

            return $leg;
        });
    }

    public function remove(RouteLeg $leg, User $actor): void
    {
        DB::transaction(function () use ($leg, $actor): void {
            $this->audit->record('route-leg.removed', $actor, $leg);
            $leg->delete();
        });
    }
}
