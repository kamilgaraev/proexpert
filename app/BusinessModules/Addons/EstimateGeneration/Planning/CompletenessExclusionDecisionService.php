<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class CompletenessExclusionDecisionService
{
    private Closure $translate;

    public function __construct(
        private ProjectModelRepository $models,
        private AuthorizationService $authorization,
        private PlanningReanalysisTrigger $reanalysis,
        ?Closure $translate = null,
    ) {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    public function exclude(
        User $actor,
        EstimateGenerationSession $session,
        ActorContext $context,
        int $completenessRunId,
        string $findingKey,
        string $reason,
    ): Decision {
        $this->authorize($actor, $session, $context);
        $decisionId = 'decision:completeness:'.hash('sha256', $context->idempotencyKey);
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
                $completenessRunId,
                $findingKey,
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

        $projection = $this->models->currentCompleteness(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
        );
        if ($projection === null || ($projection['is_current'] ?? false) !== true
            || ($projection['run_id'] ?? null) !== $completenessRunId
            || ($context->expectedSourceVersion !== null
                && ! hash_equals($context->expectedSourceVersion, (string) ($projection['source_version'] ?? '')))) {
            throw new InvalidArgumentException('Completeness run is stale.');
        }
        $finding = $this->finding($projection['findings'] ?? [], $findingKey);
        $entityId = $finding->relatedEntityIds[0] ?? null;
        if (! is_string($entityId) || $entityId === '') {
            throw new InvalidArgumentException('Completeness finding has no canonical entity scope.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Completeness exclusion reason is invalid.');
        }
        $factType = 'completeness_exclusion.'.substr(hash('sha256', $finding->ruleId), 0, 32);
        $previous = null;
        foreach ($this->models->currentFacts(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            $entityId,
        ) as $fact) {
            if ($fact instanceof Fact && $fact->type === $factType) {
                $previous = $fact;
                break;
            }
        }
        $factId = 'fact:decision:'.substr(hash('sha256', $decisionId.'|'.$findingKey), 0, 48);
        $value = $finding->exclusionValue($projection, $decisionId, (string) $context->actorId, $reason);
        $value['completeness_run_id'] = $completenessRunId;
        $selected = new Fact(
            id: $factId,
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: (int) $session->getKey(),
            sourceVersion: (string) $projection['source_version'],
            entityId: $entityId,
            type: $factType,
            value: $value,
            unit: null,
            confidence: 1.0,
            origin: 'user_assumption',
            status: 'confirmed',
            evidenceIds: [],
            version: ($previous?->version ?? 0) + 1,
            supersedesFactId: $previous?->id,
        );
        $decision = new Decision(
            id: $decisionId,
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: (int) $session->getKey(),
            sourceVersion: (string) $projection['source_version'],
            targetType: 'fact',
            targetId: $selected->id,
            selectedFactId: $selected->id,
            actorType: 'user',
            actorId: (string) $context->actorId,
            reason: $reason,
            version: $selected->version,
            evidenceIds: [],
        );
        if (! $this->models->applyCompletenessExclusionDecision(
            $decision,
            $selected,
            (string) $projection['input_fingerprint'],
            $completenessRunId,
        )) {
            throw new InvalidArgumentException('Completeness finding changed before exclusion persistence.');
        }
        $this->reanalysis->trigger((int) $session->getKey(), $context);

        return $decision;
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

    private function finding(array $findings, string $findingKey): CompletenessFinding
    {
        foreach ($findings as $finding) {
            if ($finding instanceof CompletenessFinding && hash_equals($finding->stableKey, $findingKey)) {
                if (($finding->exclusionPolicy['allowed'] ?? false) !== true) {
                    throw new InvalidArgumentException('Completeness finding cannot be excluded.');
                }

                return $finding;
            }
        }

        throw new InvalidArgumentException('Completeness finding is outside the current run.');
    }

    private function assertReplayMatches(
        Decision $decision,
        ActorContext $context,
        int $completenessRunId,
        string $findingKey,
        string $reason,
    ): void {
        $selected = $decision->selectedFactId === null ? null : $this->models->fact(
            $context->organizationId,
            $context->projectId,
            $decision->sessionId,
            $decision->selectedFactId,
        );
        if (! $selected instanceof Fact || ! is_array($selected->value)
            || ($selected->value['completeness_run_id'] ?? null) !== $completenessRunId
            || ($selected->value['finding_key'] ?? null) !== $findingKey
            || $decision->organizationId !== $context->organizationId
            || $decision->projectId !== $context->projectId
            || $decision->actorId !== (string) $context->actorId
            || $decision->reason !== trim($reason)
            || ($context->expectedSourceVersion !== null
                && ! hash_equals($context->expectedSourceVersion, $decision->sourceVersion))) {
            throw new InvalidArgumentException('Completeness exclusion idempotency payload differs.');
        }
    }
}
