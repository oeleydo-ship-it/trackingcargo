<?php

declare(strict_types=1);

namespace App\Enums;

enum DutyType: string
{
    case Duty = 'duty';
    case Tax = 'tax';
    case Fee = 'fee';

    public function label(): string
    {
        return match ($this) {
            self::Duty => 'Import duty',
            self::Tax => 'Tax',
            self::Fee => 'Fee',
        };
    }
}
