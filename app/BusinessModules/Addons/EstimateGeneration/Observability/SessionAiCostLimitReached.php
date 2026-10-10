<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

final class SessionAiCostLimitReached extends AiWireNotStarted
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
