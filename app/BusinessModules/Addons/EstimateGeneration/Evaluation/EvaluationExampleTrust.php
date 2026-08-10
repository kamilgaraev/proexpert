<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

enum EvaluationExampleTrust: string
{
    case Candidate = 'candidate';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';
}
