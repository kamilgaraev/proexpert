<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

enum PageAnalysisRoute: string
{
    case SimpleContext = 'simple_context';
    case StructuredTextual = 'structured_textual';
    case DenseAmbiguous = 'dense_ambiguous';
}
