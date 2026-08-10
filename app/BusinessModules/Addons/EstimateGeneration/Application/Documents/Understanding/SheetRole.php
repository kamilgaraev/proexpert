<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

enum SheetRole: string
{
    case Plan = 'plan';
    case Section = 'section';
    case Facade = 'facade';
    case Explication = 'explication';
    case Specification = 'specification';
    case Unknown = 'unknown';

    public function requiresStructuredFacts(): bool
    {
        return $this !== self::Unknown;
    }
}
