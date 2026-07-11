<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceCurrency: string
{
    case Rub = 'RUB';
    case Usd = 'USD';
    case Eur = 'EUR';
}
