<?php

declare(strict_types=1);

namespace App\Enums;

enum WarehouseZoneType: string
{
    case Receiving = 'receiving';
    case Storage = 'storage';
    case Staging = 'staging';
    case Dispatch = 'dispatch';
    case CustomsHold = 'customs_hold';
    case Returns = 'returns';
}
