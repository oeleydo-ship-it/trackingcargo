<?php

declare(strict_types=1);

namespace App\Enums;

enum InspectionType: string
{
    case Physical = 'physical';
    case XRay = 'xray';
    case Documentary = 'documentary';
    case Canine = 'canine';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Physical exam',
            self::XRay => 'X-ray scan',
            self::Documentary => 'Documentary review',
            self::Canine => 'Canine inspection',
            self::Other => 'Other',
        };
    }
}
