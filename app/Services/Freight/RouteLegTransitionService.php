<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\RouteLegStatus;
use App\Models\RouteLeg;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RouteLegTransitionService
{
    public function __construct(private AuditService $audit) {}

    public function transition(RouteLeg $leg, RouteLegStatus $to, User $actor): RouteLeg
    {
        $from = $leg->status;

        if (! RouteLegTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition a route leg from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        if ($to === RouteLegStatus::Departed) {
            $this->assertPriorLegsAreSettled($leg);
        }

        return DB::transaction(function () use ($leg, $from, $to, $actor): RouteLeg {
            $leg->forceFill([
                'status' => $to,
                'actual_departure_at' => $to === RouteLegStatus::Departed ? now() : $leg->actual_departure_at,
                'actual_arrival_at' => $to === RouteLegStatus::Arrived ? now() : $leg->actual_arrival_at,
            ])->save();

            $this->audit->record('route-leg.status-changed', $actor, $leg, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

            return $leg;
        });
    }

    /**
     * A leg cannot depart while an earlier leg of the same shipment is still in
     * flight — this is what "ordered mixed-mode legs" means in practice: the
     * legs may switch between air/sea/road freely, but they must resolve in order.
     */
    private function assertPriorLegsAreSettled(RouteLeg $leg): void
    {
        $unsettledPriorLeg = RouteLeg::query()
            ->where('shipment_id', $leg->shipment_id)
            ->where('sequence', '<', $leg->sequence)
            ->get()
            ->first(fn (RouteLeg $prior): bool => ! $prior->status->isSettled());

        if ($unsettledPriorLeg !== null) {
            throw ValidationException::withMessages([
                'status' => "Leg {$unsettledPriorLeg->sequence} has not arrived yet; legs must resolve in order.",
            ]);
        }
    }
}
