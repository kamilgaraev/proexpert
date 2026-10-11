<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

enum EstimateResultClass: string
{
    case Scenario = 'scenario_estimate';
    case Refined = 'refined_estimate';
    case VerifiedScope = 'verified_scope_estimate';
}
