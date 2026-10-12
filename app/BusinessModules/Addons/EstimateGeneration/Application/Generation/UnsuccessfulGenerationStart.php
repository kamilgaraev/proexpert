<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionActionResult;
use RuntimeException;

/** Rolls back a rejected start while preserving its existing result contract outside the transaction. */
final class UnsuccessfulGenerationStart extends RuntimeException
{
    public function __construct(public readonly SessionActionResult $result)
    {
        parent::__construct('estimator_generation_start_rejected');
    }
}
