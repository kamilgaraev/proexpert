<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

enum FailureCategory: string
{
    case Recoverable = 'recoverable';
    case UserActionRequired = 'user_action_required';
    case Terminal = 'terminal';
}
