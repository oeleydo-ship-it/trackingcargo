<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\MasterStatus;
use App\Models\Master;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MasterTransitionService
{
    public function __construct(private AuditService $audit) {}

    public function transition(Master $master, MasterStatus $to, User $actor): Master
    {
        $from = $master->status;

        if (! MasterTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition a master from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        if ($to === MasterStatus::Departed && $master->loadUnits()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'A master cannot depart with no load units.']);
        }

        return DB::transaction(function () use ($master, $from, $to, $actor): Master {
            $master->forceFill([
                'status' => $to,
                'actual_departure_at' => $to === MasterStatus::Departed ? now() : $master->actual_departure_at,
                'actual_arrival_at' => $to === MasterStatus::Arrived ? now() : $master->actual_arrival_at,
            ])->save();

            $this->audit->record('master.status-changed', $actor, $master, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

            return $master;
        });
    }
}
