<?php

declare(strict_types=1);

namespace App\Enums;

enum VehicleType: string
{
    case Van = 'van';
    case Truck = 'truck';
    case Motorcycle = 'motorcycle';
    case Car = 'car';

    public function label(): string
    {
        return match ($this) {
            self::Van => 'Van',
            self::Truck => 'Truck',
            self::Motorcycle => 'Motorcycle',
            self::Car => 'Car',
        };
    }
}
