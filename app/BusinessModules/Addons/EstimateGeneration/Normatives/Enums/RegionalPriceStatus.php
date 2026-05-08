<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums;

enum RegionalPriceStatus: string
{
    case DISCOVERED = 'discovered';
    case DOWNLOADED = 'downloaded';
    case PARSING = 'parsing';
    case PARSED = 'parsed';
    case CHECKED = 'checked';
    case ACTIVE = 'active';
    case SUPERSEDED = 'superseded';
    case ROLLED_BACK = 'rolled_back';
    case UNAVAILABLE = 'unavailable';
    case FAILED = 'failed';
}
