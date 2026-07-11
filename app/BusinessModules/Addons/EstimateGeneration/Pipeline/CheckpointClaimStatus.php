<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

enum CheckpointClaimStatus: string
{
    case Acquired = 'acquired';
    case AlreadyCompleted = 'already_completed';
    case Busy = 'busy';
}
