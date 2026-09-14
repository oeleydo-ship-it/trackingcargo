<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;

/**
 * `partially_paid` and `paid` are deliberately unreachable through this map
 * — they are set only by PaymentService when a real payment is applied,
 * never by a direct status transition. Same structural-guarantee shape as
 * Phase 7's DeliveryAssignmentTransitionMap keeping `delivered` unreachable.
 */
final class InvoiceTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'draft' => ['issued', 'void'],
        'issued' => ['void'],
        'partially_paid' => [],
        'paid' => [],
        'void' => [],
    ];

    public static function isAllowed(InvoiceStatus $from, InvoiceStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<InvoiceStatus> */
    public static function allowedFrom(InvoiceStatus $from): array
    {
        return array_map(
            static fn (string $status): InvoiceStatus => InvoiceStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
