<?php

declare(strict_types=1);

namespace App\Enums;

enum LoadUnitType: string
{
    case Container = 'container';
    case Pallet = 'pallet';
    case Bag = 'bag';
}
