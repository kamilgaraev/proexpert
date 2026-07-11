<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceRelation: string
{
    case DerivedFrom = 'derived_from';
    case Supports = 'supports';
    case Contradicts = 'contradicts';
    case Resolves = 'resolves';
    case MatchedTo = 'matched_to';
    case PricedBy = 'priced_by';
}
