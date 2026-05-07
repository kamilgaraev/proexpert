<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums;

enum EstimateSourceType: string
{
    case FSNB_2022 = 'fsnb_2022';
    case KSR = 'ksr';
    case FSBC = 'fsbc';
}
