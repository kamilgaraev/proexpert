<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

enum AnalysisMaterialRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function requiresArbitration(): bool
    {
        return $this !== self::Low;
    }
}
