<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\LoadUnitStatus;

final class LoadUnitTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'building' => ['loaded'],
        'loaded' => ['in_transit', 'building'],
        'in_transit' => ['arrived'],
        'arrived' => ['unloaded'],
        'unloaded' => [],
    ];

    public static function isAllowed(LoadUnitStatus $from, LoadUnitStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<LoadUnitStatus> */
    public static function allowedFrom(LoadUnitStatus $from): array
    {
        return array_map(
            static fn (string $status): LoadUnitStatus => LoadUnitStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
