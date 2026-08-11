<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class TechnologyRecommendationDecisionService
{
    private Closure $translate;

    public function __construct(
        private ProjectModelRepository $models,
        private ApplyProjectModelDecision $decisions,
        private TechnologyRecommendationService $recommendations,
        private AuthorizationService $authorization,
        private PlanningReanalysisTrigger $reanalysis,
        ?Closure $translate = null,
    ) {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    public function respond(
        User $actor,
        EstimateGenerationSession $session,
        ActorContext $context,
        int $planningRunId,
        string $decisionKey,
        string $response,
        ?string $other,
        string $reason,
    ): ?Decision {
        $this->authorize($actor, $session, $context);
        $decisionId = 'decision:technology:'.hash('sha256', $context->idempotencyKey);
        $existing = $this->models->decisions(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            [$decisionId],
        );
        if ($existing !== []) {
            $decision = $existing[0];
            $this->assertReplayMatches(
                $decision,
                $context,
                $planningRunId,
                $decisionKey,
                $response,
                $other,
                $reason,
            );
            if ($this->models->currentCompleteness(
                $context->organizationId,
                $context->projectId,
                (int) $session->getKey(),
            ) === null) {
                $this->reanalysis->trigger((int) $session->getKey(), $context);
            }

            return $decision;
        }
        $current = $this->models->currentTechnologyRecommendations(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
        );
        if ($current === null || ($current['is_current'] ?? false) !== true
            || ($current['run_id'] ?? null) !== $planningRunId
            || ($context->expectedSourceVersion !== null
                && ! hash_equals($context->expectedSourceVersion, (string) $current['source_version']))) {
            throw new InvalidArgumentException('Technology recommendation run is stale.');
        }
        $persisted = null;
        foreach ($current['recommendations'] as $candidate) {
            if ($candidate instanceof TechnologyRecommendation && $candidate->decisionKey === $decisionKey) {
                $persisted = $candidate;
                break;
            }
        }
        if (! $persisted instanceof TechnologyRecommendation) {
            throw new InvalidArgumentException('Technology recommendation is outside the requested scope.');
        }
        $snapshot = $this->models->snapshotForPlanning(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            10001,
        );
        if (! hash_equals((string) $current['input_fingerprint'], $snapshot['token'])) {
            throw new InvalidArgumentException('Technology recommendation snapshot is stale.');
        }
        $target = $this->models->fact(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            $persisted->targetFactId,
        );
        if (! $target instanceof Fact) {
            throw new InvalidArgumentException('Technology recommendation target is stale.');
        }
        $currentRecommendation = $this->recommendations->recommend(
            $snapshot['snapshot'],
            $target,
            new OrganizationPreferenceContext($context->organizationId, []),
        );
        if ($currentRecommendation->decisionKey !== $persisted->decisionKey
            || $currentRecommendation->catalogVersion !== $persisted->catalogVersion
            || ! hash_equals($currentRecommendation->catalogHash, $persisted->catalogHash)) {
            throw new InvalidArgumentException('Technology recommendation catalog is stale.');
        }
        if ($response === 'leave_unresolved') {
            return null;
        }
        $value = $this->value($currentRecommendation, $response, $other);
        $value['decision_key'] = $decisionKey;
        $value['planning_run_id'] = $planningRunId;
        $decision = $this->decisions->applyTechnologyChoice(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: (int) $session->getKey(),
            sourceVersion: $currentRecommendation->sourceVersion,
            factId: $currentRecommendation->targetFactId,
            value: $value,
            unit: null,
            actorId: (string) $context->actorId,
            reason: $reason,
            decisionId: $decisionId,
            inputFingerprint: (string) $current['input_fingerprint'],
            planningRunId: $planningRunId,
        );
        $this->reanalysis->trigger((int) $session->getKey(), $context);

        return $decision;
    }

    private function assertReplayMatches(
        Decision $decision,
        ActorContext $context,
        int $planningRunId,
        string $decisionKey,
        string $response,
        ?string $other,
        string $reason,
    ): void {
        $selected = $decision->selectedFactId === null ? null : $this->models->fact(
            $context->organizationId,
            $context->projectId,
            $decision->sessionId,
            $decision->selectedFactId,
        );
        $matchesResponse = $selected instanceof Fact
            && is_array($selected->value)
            && ($selected->value['decision_key'] ?? null) === $decisionKey
            && ($selected->value['planning_run_id'] ?? null) === $planningRunId
            && match ($response) {
                'other' => ($selected->value['kind'] ?? null) === 'other'
                    && is_string($other)
                    && ($selected->value['other'] ?? null) === trim($other),
                'leave_unresolved' => false,
                default => ($selected->value['kind'] ?? null) === 'catalog_system'
                    && ($selected->value['system_id'] ?? null) === $response,
            };
        if (! $matchesResponse
            || $decision->organizationId !== $context->organizationId
            || $decision->projectId !== $context->projectId
            || $decision->actorId !== (string) $context->actorId
            || $decision->reason !== $reason
            || ($context->expectedSourceVersion !== null
                && ! hash_equals($context->expectedSourceVersion, $decision->sourceVersion))) {
            throw new InvalidArgumentException('Technology decision idempotency payload differs.');
        }
    }

    private function authorize(User $actor, EstimateGenerationSession $session, ActorContext $context): void
    {
        if ((int) $actor->getKey() !== $context->actorId
            || (int) $actor->current_organization_id !== $context->organizationId
            || (int) $session->organization_id !== $context->organizationId
            || (int) $session->project_id !== $context->projectId
            || ! $this->authorization->can($actor, 'estimate_generation.update', [
                'organization_id' => $context->organizationId,
                'project_id' => $context->projectId,
            ])) {
            throw new AuthorizationException(($this->translate)('estimate_generation.access_denied'));
        }
    }

    private function value(TechnologyRecommendation $recommendation, string $response, ?string $other): array
    {
        if ($response === 'other') {
            if ($other === null || trim($other) === '' || mb_strlen($other) > 500) {
                throw new InvalidArgumentException('Other technology system is invalid.');
            }

            return [
                'kind' => 'other',
                'other' => trim($other),
                'catalog_version' => $recommendation->catalogVersion,
                'catalog_hash' => $recommendation->catalogHash,
            ];
        }
        foreach ($recommendation->options as $option) {
            if ($option instanceof TechnologySystemOption && $option->system->id === $response) {
                if ($option->applicabilityStatus !== 'applicable') {
                    throw new InvalidArgumentException('Technology system is not currently applicable.');
                }

                return [
                    'kind' => 'catalog_system',
                    'system_id' => $option->system->id,
                    'catalog_version' => $recommendation->catalogVersion,
                    'catalog_hash' => $recommendation->catalogHash,
                    'provenance' => $option->system->provenance,
                ];
            }
        }

        throw new InvalidArgumentException('Technology system response is invalid.');
    }
}
