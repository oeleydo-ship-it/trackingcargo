<?php

declare(strict_types=1);

namespace App\Services\Customs;

use App\Enums\CustomsClearanceStatus;
use App\Enums\ShipmentStatusRole;
use App\Models\CustomsClearance;
use App\Models\CustomsClearanceEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent by the same "check-then-act, catch 23000, re-fetch" shape as
 * Phase 5's WarehouseScanService: a retried hold/clear/reject request carrying
 * the same idempotency_key is returned as-is with zero re-applied side
 * effects — critical here specifically because a clear/reject transition also
 * advances the shipment's own status, and a naive retry would otherwise hit
 * the transition rules' guard a second time and fail even though the first
 * request already succeeded.
 */
final readonly class CustomsClearanceTransitionService
{
    public function __construct(
        private AuditService $audit,
        private ShipmentTransitionService $shipmentTransitions,
    ) {}

    public function transition(
        CustomsClearance $clearance,
        CustomsClearanceStatus $to,
        User $actor,
        string $idempotencyKey,
        ?string $reason = null,
        ?ShipmentStatusRole $nextShipmentStatus = null,
    ): CustomsClearanceEvent {
        $existing = CustomsClearanceEvent::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $from = $clearance->status;

        if (! CustomsClearanceTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition a customs clearance from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        try {
            $event = DB::transaction(function () use ($clearance, $from, $to, $actor, $idempotencyKey, $reason, $nextShipmentStatus): CustomsClearanceEvent {
                $clearance->forceFill([
                    'status' => $to,
                    'cleared_at' => $to === CustomsClearanceStatus::Cleared ? now() : $clearance->cleared_at,
                ])->save();

                $event = $clearance->events()->create([
                    'from_status' => $from,
                    'to_status' => $to,
                    'reason' => $reason,
                    'actor_id' => $actor->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => now(),
                ]);

                $this->audit->record('customs-clearance.status-changed', $actor, $clearance, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

                if ($to === CustomsClearanceStatus::Cleared) {
                    $this->shipmentTransitions->transitionToRole($clearance->shipment, $nextShipmentStatus ?? ShipmentStatusRole::InTransit, $actor);
                } elseif ($to === CustomsClearanceStatus::Rejected) {
                    $this->shipmentTransitions->transitionToRole($clearance->shipment, ShipmentStatusRole::Exception, $actor);
                }

                return $event;
            });
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() !== 23000) {
                throw $exception;
            }

            return CustomsClearanceEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        return $event;
    }
}
