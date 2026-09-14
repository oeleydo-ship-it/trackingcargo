<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\LoadUnitStatus;
use App\Models\LoadUnit;
use App\Models\Master;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class LoadUnitService
{
    private const array FIELDS = ['type', 'unit_number', 'seal_number'];

    public function __construct(private AuditService $audit) {}

    public function create(Master $master, array $data, User $actor): LoadUnit
    {
        return DB::transaction(function () use ($master, $data, $actor): LoadUnit {
            $unit = $master->loadUnits()->create([
                ...Arr::only($data, self::FIELDS),
                'status' => LoadUnitStatus::Building,
            ]);

            $this->audit->record('load-unit.created', $actor, $unit, newValues: $unit->only(self::FIELDS));

            return $unit;
        });
    }
}
