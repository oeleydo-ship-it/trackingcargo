<?php

declare(strict_types=1);

namespace App\Enums;

enum MasterStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Departed = 'departed';
    case Arrived = 'arrived';
    case ClosedOut = 'closed_out';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed for loading',
            self::Departed => 'Departed',
            self::Arrived => 'Arrived',
            self::ClosedOut => 'Closed out',
            self::Cancelled => 'Cancelled',
        };
    }
}
