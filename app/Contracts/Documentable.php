<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Implemented by any model that can own private `documents` rows (Phase 6's
 * CustomsClearance, Phase 7's DeliveryAttempt for POD). Keeps DocumentService
 * generic without genericizing further than there are real callers for.
 */
interface Documentable
{
    public function documents(): MorphMany;

    /** Opaque storage folder segment — never derived from user input. */
    public function documentStoragePath(): string;
}
