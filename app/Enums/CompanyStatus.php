<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';

    /** Signed up publicly and waiting for a superadmin to approve it. */
    case Pending = 'pending';

    case Suspended = 'suspended';
    case Inactive = 'inactive';
}
