<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceType: string
{
    case SourceFact = 'source_fact';
    case Extracted = 'extracted';
    case Measured = 'measured';
    case Inferred = 'inferred';
    case WorkItem = 'work_item';
    case NormativeMatch = 'normative_match';
    case Price = 'price';
}
