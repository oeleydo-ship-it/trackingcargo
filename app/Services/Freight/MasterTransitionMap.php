<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\MasterStatus;

final class MasterTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'open' => ['closed', 'cancelled'],
        'closed' => ['open', 'departed', 'cancelled'],
        'departed' => ['arrived'],
        'arrived' => ['closed_out'],
        'closed_out' => [],
        'cancelled' => [],
    ];

    public static function isAllowed(MasterStatus $from, MasterStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<MasterStatus> */
    public static function allowedFrom(MasterStatus $from): array
    {
        return array_map(
            static fn (string $status): MasterStatus => MasterStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
