<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeDirection: string
{
    case Import = 'import';
    case Export = 'export';
    case ProhelperToOneC = 'prohelper_to_1c';
    case OneCToProhelper = '1c_to_prohelper';
}
