<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Enums;

enum AssetOperationalMode: string
{
    case Custody = 'custody';
    case SiteOperation = 'site_operation';
    case ShiftOperation = 'shift_operation';
}
