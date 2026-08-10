<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

enum FailureRecoveryOutcome
{
    case Retry;
    case NeedsInput;
    case Terminal;
    case Ignore;
}
