<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceConfidenceBand: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
