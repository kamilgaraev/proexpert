<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class ApplyProjectModelDecision
{
    public function __construct(private ProjectModelRepository $models) {}

    public function apply(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $factId,
        mixed $value,
        ?string $unit,
        string $actorId,
        string $reason,
        string $decisionId,
    ): Decision {
        [$decision, $selected] = $this->build(
            $organizationId, $projectId, $sessionId, $sourceVersion, $factId,
            $value, $unit, $actorId, $reason, $decisionId,
        );
        $this->models->applyDecision($decision, $selected);

        return $decision;
    }

    public function applyTechnologyChoice(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $factId,
        mixed $value,
        ?string $unit,
        string $actorId,
        string $reason,
        string $decisionId,
        string $inputFingerprint,
        int $planningRunId,
    ): Decision {
        [$decision, $selected] = $this->build(
            $organizationId, $projectId, $sessionId, $sourceVersion, $factId,
            $value, $unit, $actorId, $reason, $decisionId,
        );
        if (! $this->models->applyTechnologyDecision($decision, $selected, $inputFingerprint, $planningRunId)) {
            throw new InvalidArgumentException('Technology recommendation changed before decision persistence.');
        }

        return $decision;
    }

    private function build(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $factId,
        mixed $value,
        ?string $unit,
        string $actorId,
        string $reason,
        string $decisionId,
    ): array {
        $original = $this->models->fact($organizationId, $projectId, $sessionId, $factId);
        if (! $original instanceof Fact || $original->sourceVersion !== $sourceVersion) {
            throw new InvalidArgumentException('Project model decision target is outside the requested scope.');
        }
        $selectedId = $this->selectedFactId($decisionId, $factId);
        $selected = $this->models->fact($organizationId, $projectId, $sessionId, $selectedId);
        if (! $selected instanceof Fact) {
            $base = $original;
            foreach ($this->models->currentFacts($organizationId, $projectId, $sessionId, $original->entityId) as $current) {
                if ($current->type === $original->type && $current->sourceVersion === $sourceVersion) {
                    $base = $current;
                    break;
                }
            }
            $selected = new Fact(
                id: $selectedId,
                organizationId: $organizationId,
                projectId: $projectId,
                sessionId: $sessionId,
                sourceVersion: $sourceVersion,
                entityId: $original->entityId,
                type: $original->type,
                value: $value,
                unit: $unit,
                confidence: 1.0,
                origin: 'user_assumption',
                status: 'confirmed',
                evidenceIds: $original->evidenceIds,
                version: $base->version + 1,
                supersedesFactId: $base->id,
            );
        }
        $decision = new Decision(
            id: $decisionId,
            organizationId: $organizationId,
            projectId: $projectId,
            sessionId: $sessionId,
            sourceVersion: $sourceVersion,
            targetType: 'fact',
            targetId: $original->id,
            selectedFactId: $selected->id,
            actorType: 'user',
            actorId: $actorId,
            reason: $reason,
            version: $selected->version,
            evidenceIds: $original->evidenceIds,
        );

        return [$decision, $selected];
    }

    private function selectedFactId(string $decisionId, string $factId): string
    {
        if (preg_match('/^correction:([a-f0-9]{64})$/D', $decisionId, $matches) === 1) {
            return 'fact:decision:'.substr($matches[1], 0, 48);
        }

        return 'fact:decision:'.substr(hash('sha256', $decisionId.'|'.$factId), 0, 48);
    }
}
