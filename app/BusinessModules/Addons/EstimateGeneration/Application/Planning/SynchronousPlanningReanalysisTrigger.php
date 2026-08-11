<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use Illuminate\Support\Str;

final readonly class SynchronousPlanningReanalysisTrigger implements PlanningReanalysisTrigger
{
    public function __construct(private ProjectPlanningPipeline $pipeline) {}

    public function trigger(int $sessionId, ActorContext $context): void
    {
        $this->pipeline->refresh(
            $context->organizationId,
            $context->projectId,
            $sessionId,
            (string) Str::uuid(),
            1,
        );
    }
}
