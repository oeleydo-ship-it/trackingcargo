<?php

declare(strict_types=1);

namespace App\Enums;

enum MasterMode: string
{
    case Air = 'air';
    case Sea = 'sea';
}
