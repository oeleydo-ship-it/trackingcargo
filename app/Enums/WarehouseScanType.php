<?php

declare(strict_types=1);

namespace App\Enums;

enum WarehouseScanType: string
{
    case Receive = 'receive';
    case Sort = 'sort';
    case Load = 'load';
    case Unload = 'unload';
    case Dispatch = 'dispatch';

    public function label(): string
    {
        return match ($this) {
            self::Receive => 'Received',
            self::Sort => 'Sorted',
            self::Load => 'Loaded',
            self::Unload => 'Unloaded',
            self::Dispatch => 'Dispatched',
        };
    }
}
