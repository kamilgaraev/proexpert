<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums;

enum EstimateResourceType: string
{
    case MATERIAL = 'material';
    case EQUIPMENT = 'equipment';
    case MACHINE = 'machine';
    case LABOR = 'labor';
    case SUMMARY = 'summary';
    case OTHER = 'other';
}
