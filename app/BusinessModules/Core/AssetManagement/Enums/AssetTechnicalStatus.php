<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Enums;

enum AssetTechnicalStatus: string
{
    case Serviceable = 'serviceable';
    case Restricted = 'restricted';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';
}
