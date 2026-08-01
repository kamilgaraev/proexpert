<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

enum SheetRole: string
{
    case Plan = 'plan';
    case Section = 'section';
    case Elevation = 'elevation';
    case Detail = 'detail';
    case Explication = 'explication';
    case Specification = 'specification';
    case Visualization = 'visualization';
    case Unknown = 'unknown';

    public function requiresStructuredFacts(): bool
    {
        return ! in_array($this, [self::Visualization, self::Unknown], true);
    }
}
