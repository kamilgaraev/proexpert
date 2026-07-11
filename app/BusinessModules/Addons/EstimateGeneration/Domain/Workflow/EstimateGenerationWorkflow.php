<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EstimateGenerationWorkflow
{
    public function __construct(
        private EstimateGenerationTransitionMap $transitionMap,
        private SessionStateStore $stateStore,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        EstimateGenerationSession $session,
        EstimateGenerationEvent $event,
        array $attributes = [],
    ): EstimateGenerationSession {
        $currentStatus = $session->status;
        $targetStatus = $this->transitionMap->resolve($currentStatus, $event, $session->resume_status);

        unset($attributes['status'], $attributes['state_version'], $attributes['resume_status']);
        $attributes['resume_status'] = null;

        if ($event === EstimateGenerationEvent::Failed) {
            $attributes['resume_status'] = $currentStatus->value;
        }

        $expectedVersion = $session->state_version;

        return $this->stateStore->compareAndSet(
            $session,
            $expectedVersion,
            $targetStatus,
            $attributes,
        );

    }

    /** @param array<string, mixed> $attributes */
    public function update(EstimateGenerationSession $session, array $attributes): EstimateGenerationSession
    {
        unset($attributes['status'], $attributes['state_version'], $attributes['resume_status']);

        return $this->stateStore->compareAndSet(
            $session,
            $session->state_version,
            $session->status,
            $attributes,
        );
    }
}
