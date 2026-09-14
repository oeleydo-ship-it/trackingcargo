<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentMode: string
{
    case Air = 'air';
    case Sea = 'sea';
    case Road = 'road';
    case Courier = 'courier';
}
