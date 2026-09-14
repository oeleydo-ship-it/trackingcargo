<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\DeliveryAssignmentStatus;

/**
 * `delivered` is deliberately unreachable through this map — it is set only
 * by DeliveryAttemptService on a successful attempt, never by a direct
 * status transition, which is what makes "completion requires POD" a
 * structural guarantee rather than a convention.
 */
final class DeliveryAssignmentTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'assigned' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public static function isAllowed(DeliveryAssignmentStatus $from, DeliveryAssignmentStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<DeliveryAssignmentStatus> */
    public static function allowedFrom(DeliveryAssignmentStatus $from): array
    {
        return array_map(
            static fn (string $status): DeliveryAssignmentStatus => DeliveryAssignmentStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
