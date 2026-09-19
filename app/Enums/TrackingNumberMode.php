<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a shipment's tracking number is produced when it is booked. Only the
 * booking form's starting choice is configurable (per company, overridable per
 * branch); staff can still pick another method for any one shipment.
 */
enum TrackingNumberMode: string
{
    /** Allocated from the configured pattern. */
    case Auto = 'auto';

    /** The pattern's running number is replaced by a receipt or company reference typed in. */
    case Suffix = 'suffix';

    /** The whole tracking number is typed in; only where the company allows manual numbers. */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Generate automatically',
            self::Suffix => 'Company / branch + receipt reference',
            self::Full => 'Enter entire tracking number',
        };
    }
}
