<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

enum DocumentProcessingUnitClaimStatus: string
{
    case Acquired = 'acquired';
    case AlreadyCompleted = 'already_completed';
    case Busy = 'busy';
    case Exhausted = 'exhausted';
    case Stale = 'stale';
}
