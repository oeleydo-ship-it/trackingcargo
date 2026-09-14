<?php

declare(strict_types=1);

namespace App\Enums;

enum AddressType: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Other = 'other';
}
