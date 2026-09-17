<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a party's field an anonymous visitor to the public tracking page
 * may see. What "masked" means is per field — see PublicTrackingFieldPolicy.
 */
enum PublicFieldVisibility: string
{
    case Hidden = 'hidden';
    case Masked = 'masked';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Hidden',
            self::Masked => 'Partial',
            self::Full => 'Full',
        };
    }
}
