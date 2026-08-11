<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Enums;

enum AssetLifecycleStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
    case Lost = 'lost';
}
