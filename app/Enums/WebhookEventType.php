<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The event types a WebhookEndpoint can subscribe to. Deliberately small —
 * two meaningful, high-value events (a shipment's status changing, an
 * invoice being fully paid) rather than wiring every domain action in the
 * app into the webhook system in one phase. WebhookEndpoint.event_types
 * also accepts the literal wildcard "*" (see WebhookEndpoint::subscribesTo()),
 * kept as a plain string rather than a case here since it is not itself an
 * event that ever gets dispatched.
 */
enum WebhookEventType: string
{
    case ShipmentStatusChanged = 'shipment.status_changed';
    case InvoicePaid = 'invoice.paid';

    public function label(): string
    {
        return match ($this) {
            self::ShipmentStatusChanged => 'Shipment status changed',
            self::InvoicePaid => 'Invoice paid',
        };
    }
}
