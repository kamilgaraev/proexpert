<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums;

enum EstimateNormType: string
{
    case GESN = 'gesn';
    case GESNM = 'gesnm';
    case GESNMR = 'gesnmr';
    case GESNP = 'gesnp';
    case GESNR = 'gesnr';
    case FSBC_MATERIAL = 'fsbc_material';
    case FSBC_MACHINE = 'fsbc_machine';
}
