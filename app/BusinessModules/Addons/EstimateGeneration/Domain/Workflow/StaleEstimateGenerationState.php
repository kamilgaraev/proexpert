<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use RuntimeException;

final class StaleEstimateGenerationState extends RuntimeException
{
    public function __construct(int $sessionId, int $expectedVersion)
    {
        parent::__construct(sprintf(
            'Estimate generation session %d no longer has state version %d.',
            $sessionId,
            $expectedVersion,
        ));
    }
}
