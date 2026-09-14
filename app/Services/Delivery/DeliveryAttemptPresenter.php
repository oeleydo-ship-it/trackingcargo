<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\DeliveryAttempt;

/**
 * Shared DTO shaping for an attempt, reused by the DeliveryAttemptRecorded
 * broadcast payload and the dispatch board's initial Inertia props, so the
 * live feed and the page's first paint always agree on shape.
 */
final readonly class DeliveryAttemptPresenter
{
    /** @return array<string, mixed> */
    public static function present(DeliveryAttempt $attempt): array
    {
        $attempt->loadMissing(['assignment.shipment', 'assignment.driver.user']);

        return [
            'id' => $attempt->getKey(),
            'outcome' => $attempt->outcome->value,
            'outcome_label' => $attempt->outcome->label(),
            'shipment_tracking_number' => $attempt->assignment->shipment->tracking_number,
            'driver_name' => $attempt->assignment->driver->user->name,
            'recipient_name' => $attempt->recipient_name,
            'failure_reason' => $attempt->failure_reason,
            'attempted_at' => $attempt->attempted_at->toIso8601String(),
        ];
    }
}
