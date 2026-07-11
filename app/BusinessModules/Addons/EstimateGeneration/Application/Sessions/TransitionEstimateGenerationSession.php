<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class TransitionEstimateGenerationSession
{
    public function __construct(private EstimateGenerationWorkflow $workflow) {}

    public function handle(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationEvent $event,
    ): EstimateGenerationSession {
        if ((int) $session->state_version !== $expectedVersion) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }

        return $this->workflow->transition($session, $event);
    }
}
