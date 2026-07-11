<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

enum FailureWorkflowAction
{
    case None;
    case ReviewDocuments;
    case ReviewGeneration;
    case Fail;
}
