<?php

declare(strict_types=1);

namespace App\Enums;

enum InspectionStatus: string
{
    case Scheduled = 'scheduled';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
        };
    }
}
