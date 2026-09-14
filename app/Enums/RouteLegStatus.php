<?php

declare(strict_types=1);

namespace App\Enums;

enum RouteLegStatus: string
{
    case Planned = 'planned';
    case Loaded = 'loaded';
    case Departed = 'departed';
    case Arrived = 'arrived';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Loaded => 'Loaded',
            self::Departed => 'Departed',
            self::Arrived => 'Arrived',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isSettled(): bool
    {
        return match ($this) {
            self::Arrived, self::Completed, self::Cancelled => true,
            default => false,
        };
    }
}
