<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One row per (user, type, channel) in `notification_preferences` gates
 * whether a notification of this type actually sends on that channel — see
 * App\Notifications\Concerns\RespectsNotificationPreferences. `channels()`
 * is the *candidate* set a type can ever use; a user's preference further
 * narrows it, defaulting to "on" when no preference row exists (opt-out,
 * not opt-in — appropriate for a B2B operations tool where missing an
 * invoice or a delivery confirmation is worse than one extra email).
 */
enum NotificationType: string
{
    case ShipmentDelivered = 'shipment.delivered';
    case InvoiceIssued = 'invoice.issued';
    case WebhookDeliveryFailed = 'webhook.delivery-failed';

    public function label(): string
    {
        return match ($this) {
            self::ShipmentDelivered => 'Shipment delivered',
            self::InvoiceIssued => 'Invoice issued',
            self::WebhookDeliveryFailed => 'Webhook delivery failed',
        };
    }

    /** @return list<string> */
    public function channels(): array
    {
        return match ($this) {
            self::ShipmentDelivered => ['mail', 'database', 'broadcast'],
            self::InvoiceIssued => ['mail', 'database'],
            self::WebhookDeliveryFailed => ['mail', 'database'],
        };
    }
}
