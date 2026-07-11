<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EstimateGenerationWorkflow
{
    public function __construct(
        private EstimateGenerationTransitionMap $transitionMap,
        private SessionStateStore $stateStore,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function transition(
        EstimateGenerationSession $session,
        EstimateGenerationEvent $event,
        array $attributes = [],
    ): EstimateGenerationSession {
        $currentStatus = $session->status;
        $targetStatus = $this->transitionMap->resolve($currentStatus, $event, $session->resume_status);

        if ($event === EstimateGenerationEvent::Failed) {
            $attributes['resume_status'] = $currentStatus->value;
        } elseif ($event === EstimateGenerationEvent::Retried) {
            $attributes['resume_status'] = null;
        }

        return $this->stateStore->compareAndSet(
            (int) $session->getKey(),
            $session->state_version,
            $targetStatus,
            $attributes,
        );
    }
}
