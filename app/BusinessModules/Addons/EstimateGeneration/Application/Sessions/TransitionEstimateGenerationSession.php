<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SessionProcessingStopper;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
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
        private \App\BusinessModules\Addons\EstimateGeneration\Services\Billing\StoppedSessionQuotaReleaser $quota,
        private PlanningReanalysisTrigger $planning,
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
                $updated = $this->workflow->transition($locked, $event, $event === EstimateGenerationEvent::InputConfirmed
                    ? ['input_payload' => [...($locked->input_payload ?? []), 'input_confirmed' => true]] : []);
                if ($event === EstimateGenerationEvent::InputConfirmed) {
                    $this->planning->trigger((int) $updated->getKey(), new ActorContext(
                        (int) $updated->organization_id, (int) $updated->project_id, (int) $actor->getKey(),
                        'input-confirmed:'.$updated->getKey().':'.$updated->state_version,
                    ));
                }
                if (in_array($event, [EstimateGenerationEvent::Cancelled, EstimateGenerationEvent::Archived], true)) {
                    $this->documents->haltForSession($updated, $actor);
                    $this->quota->releaseCancelledReservation($updated);
                    if (config('estimate-generation.credits.enabled', false) || isset($updated->input_payload['generation_credit_operation_id'])
                        || isset($updated->input_payload['documents_credit_operation_id'])) {
                        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abortForSession($updated);
                    }
                }

                return $updated;
            });
    }
}
