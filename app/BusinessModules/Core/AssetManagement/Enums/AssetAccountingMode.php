<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Enums;

enum AssetAccountingMode: string
{
    case Quantitative = 'quantitative';
    case Serialized = 'serialized';
}
