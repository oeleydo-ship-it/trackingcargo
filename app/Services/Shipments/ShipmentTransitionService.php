<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentStatusRole;
use App\Enums\WebhookEventType;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\ShipmentDeliveredNotification;
use App\Services\Audit\AuditService;
use App\Services\Webhooks\WebhookDispatchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class ShipmentTransitionService
{
    public function __construct(
        private AuditService $audit,
        private WebhookDispatchService $webhooks,
        private ShipmentStatusRepository $statuses,
    ) {}

    /**
     * Moves a shipment to the status carrying a system role, whatever the
     * company has named it.
     *
     * This is what customs, dispatch and delivery call: they know they are
     * putting a shipment "at customs" or "delivered", but not what this
     * particular company calls that.
     */
    public function transitionToRole(
        Shipment $shipment,
        ShipmentStatusRole $role,
        ?User $actor,
        ?string $location = null,
        ?string $description = null,
        bool $isPublic = true,
    ): TrackingEvent {
        return $this->transition(
            $shipment,
            $this->statuses->byRole($role, (int) $shipment->company_id),
            $actor,
            $location,
            $description,
            $isPublic,
        );
    }

    /**
     * $actor is nullable to support system-driven transitions (e.g.
     * CarrierTrackingPollService, a scheduled job with no human actor) —
     * AuditService::record() and tracking_events.created_by both already
     * accept a null actor, attributing the change to "system" rather than
     * a person.
     *
     * This is the single choke point every caller (delivery, customs,
     * carrier polling) already goes through to change a shipment's status,
     * so the `shipment.status_changed` webhook and the delivered-customer
     * notification are dispatched from here once, after commit, rather than
     * duplicated in every calling service.
     */
    public function transition(
        Shipment $shipment,
        ShipmentStatus $to,
        ?User $actor,
        ?string $location = null,
        ?string $description = null,
        bool $isPublic = true,
        ?string $occurredAt = null,
    ): TrackingEvent {
        $companyId = (int) $shipment->company_id;
        $eventTime = $occurredAt === null ? now() : Carbon::parse($occurredAt)->utc();
        if ($eventTime->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'The status date cannot be in the future.']);
        }

        if ((int) $to->company_id !== $companyId) {
            throw new RuntimeException('That status belongs to a different company.');
        }

        $from = $this->statuses->byCode((string) $shipment->status, $companyId);

        if ($from === null) {
            throw new RuntimeException("Shipment {$shipment->tracking_number} is in an unknown status \"{$shipment->status}\".");
        }

        if (! $this->statuses->isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move a shipment from \"{$from->name}\" to \"{$to->name}\".",
            ]);
        }

        $event = DB::transaction(function () use ($shipment, $from, $to, $actor, $location, $description, $isPublic, $eventTime): TrackingEvent {
            $shipment->forceFill([
                'status' => $to->code,
                'last_status_at' => $eventTime,
                'last_location' => $location ?? $shipment->last_location,
                'booked_at' => $to->hasRole(ShipmentStatusRole::Booked) ? $eventTime : $shipment->booked_at,
                'delivered_at' => $to->hasRole(ShipmentStatusRole::Delivered) ? $eventTime : $shipment->delivered_at,
            ])->save();

            // The status relation is joined on the code that just changed.
            $shipment->unsetRelation('shipmentStatus');

            $event = $shipment->trackingEvents()->create([
                'created_by' => $actor?->getKey(),
                'from_status' => $from->code,
                'to_status' => $to->code,
                'location' => $location,
                'description' => $description,
                'is_public' => $isPublic,
                'occurred_at' => $eventTime,
            ]);

            $this->audit->record('shipment.status-changed', $actor, $shipment, oldValues: ['status' => $from->code], newValues: ['status' => $to->code, 'occurred_at' => $eventTime->toIso8601String()]);

            return $event;
        });

        $this->webhooks->dispatch(WebhookEventType::ShipmentStatusChanged, [
            'shipment_id' => $shipment->getKey(),
            'tracking_number' => $shipment->tracking_number,
            'from_status' => $from->code,
            'to_status' => $to->code,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ], $companyId);

        if ($to->hasRole(ShipmentStatusRole::Delivered)) {
            $portalUser = $shipment->customer?->portalUser;
            $portalUser?->notify(new ShipmentDeliveredNotification($shipment));
        }

        return $event;
    }
}
