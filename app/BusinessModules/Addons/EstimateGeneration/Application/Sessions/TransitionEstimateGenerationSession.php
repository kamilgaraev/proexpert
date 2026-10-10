<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SessionProcessingStopper;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;

final class TransitionEstimateGenerationSession
{
    public function __construct(
        private EstimateGenerationWorkflow $workflow,
        private EstimateGenerationActionAuthorization $authorizer,
        private SessionProcessingStopper $documents,
        private RetryableEstimateGenerationSessionRepository $repository,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationEvent $event,
        User|SystemAdmin $actor,
    ): EstimateGenerationSession {
        return $this->repository->withLockedSession((int) $session->getKey(), (int) $session->organization_id,
            (int) $session->project_id, function (EstimateGenerationSession $locked) use ($expectedVersion, $event, $actor): EstimateGenerationSession {
                $this->authorizer->authorize($actor, $locked, $event === EstimateGenerationEvent::InputConfirmed
                    ? 'estimate_generation.review' : 'estimate_generation.generate');
                if (! in_array($event, [EstimateGenerationEvent::InputConfirmed, EstimateGenerationEvent::Cancelled, EstimateGenerationEvent::Archived], true)) {
                    throw new InvalidEstimateGenerationTransition($locked->status, $event);
                }
                if ((int) $locked->state_version !== $expectedVersion) {
                    throw new StaleEstimateGenerationState((int) $locked->getKey(), $expectedVersion);
                }
                $updated = $this->workflow->transition($locked, $event);
                if (in_array($event, [EstimateGenerationEvent::Cancelled, EstimateGenerationEvent::Archived], true)) {
                    $this->documents->haltForSession($updated, $actor);
                }

                return $updated;
            });
    }
}
