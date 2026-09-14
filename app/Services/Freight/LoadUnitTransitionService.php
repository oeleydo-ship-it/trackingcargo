<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\LoadUnitStatus;
use App\Models\LoadUnit;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class LoadUnitTransitionService
{
    public function __construct(private AuditService $audit) {}

    public function transition(LoadUnit $unit, LoadUnitStatus $to, User $actor): LoadUnit
    {
        $from = $unit->status;

        if (! LoadUnitTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition a load unit from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        if ($to === LoadUnitStatus::Loaded && $unit->package_count === 0) {
            throw ValidationException::withMessages(['status' => 'A load unit cannot be marked loaded while empty.']);
        }

        return DB::transaction(function () use ($unit, $from, $to, $actor): LoadUnit {
            $unit->forceFill(['status' => $to])->save();

            $this->audit->record('load-unit.status-changed', $actor, $unit, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

            return $unit;
        });
    }
}
