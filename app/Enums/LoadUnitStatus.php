<?php

declare(strict_types=1);

namespace App\Enums;

enum LoadUnitStatus: string
{
    case Building = 'building';
    case Loaded = 'loaded';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Unloaded = 'unloaded';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'Building',
            self::Loaded => 'Loaded',
            self::InTransit => 'In transit',
            self::Arrived => 'Arrived',
            self::Unloaded => 'Unloaded',
        };
    }
}
