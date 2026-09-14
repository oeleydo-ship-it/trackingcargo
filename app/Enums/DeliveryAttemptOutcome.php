<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryAttemptOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
