<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

enum QuantitySource: string
{
    case Evidenced = 'evidenced';
    case Estimated = 'estimated';
}
