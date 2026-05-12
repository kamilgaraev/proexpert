<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeDirection: string
{
    case Import = 'import';
    case Export = 'export';
}
