<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;

interface PlanningReanalysisTrigger
{
    public function trigger(int $sessionId, ActorContext $context): void;
}
