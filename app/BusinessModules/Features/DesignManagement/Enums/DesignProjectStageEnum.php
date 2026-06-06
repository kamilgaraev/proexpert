<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignProjectStageEnum: string
{
    case PD = 'pd';
    case RD = 'rd';
    case SURVEY = 'survey';
    case BIM = 'bim';
}
