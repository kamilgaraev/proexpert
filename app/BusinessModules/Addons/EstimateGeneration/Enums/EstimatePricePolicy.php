<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Enums;

enum EstimatePricePolicy: string
{
    case Normative = 'normative';
    case Catalog = 'catalog';
    case Mixed = 'mixed';
}
