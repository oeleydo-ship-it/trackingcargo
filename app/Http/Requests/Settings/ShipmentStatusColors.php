<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

/**
 * The palette a status may be given.
 *
 * Colours are stored as tokens rather than CSS so the front end keeps control
 * of how each one renders in light and dark, and so an arbitrary string can
 * never reach a class attribute.
 */
final class ShipmentStatusColors
{
    /** @var list<string> */
    public const array ALL = ['slate', 'cyan', 'blue', 'indigo', 'violet', 'amber', 'emerald', 'rose'];
}
