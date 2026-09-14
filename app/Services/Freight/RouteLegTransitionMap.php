<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\RouteLegStatus;

final class RouteLegTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'planned' => ['loaded', 'cancelled'],
        'loaded' => ['departed', 'cancelled'],
        'departed' => ['arrived'],
        'arrived' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public static function isAllowed(RouteLegStatus $from, RouteLegStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<RouteLegStatus> */
    public static function allowedFrom(RouteLegStatus $from): array
    {
        return array_map(
            static fn (string $status): RouteLegStatus => RouteLegStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
