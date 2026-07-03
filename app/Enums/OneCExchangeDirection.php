<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeDirection: string
{
    case Import = 'import';
    case Export = 'export';
    case MostToOneC = 'most_to_1c';
    case OneCToMost = '1c_to_most';
}
