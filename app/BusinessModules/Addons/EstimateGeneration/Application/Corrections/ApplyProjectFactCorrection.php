<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Corrections;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionConflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class ApplyProjectFactCorrection
{
    public function __construct(
        private ProjectModelRepository $models,
        private ApplyProjectModelDecision $decisions,
    ) {}

    /** @return array{decision_id:string,reanalysis_requested:bool} */
    public function apply(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $actorId,
        string $sourceVersion,
        string $expectedValueFingerprint,
        string $factId,
        array $value,
        string $reason,
        string $idempotencyKey,
        int $expectedDecisionVersion = 0,
    ): array {
        $target = $this->models->fact($organizationId, $projectId, $sessionId, $factId);
        $fact = $target;
        if ($target !== null) {
            foreach ($this->models->currentFacts($organizationId, $projectId, $sessionId, $target->entityId) as $current) {
                if ($current->type === $target->type && $current->sourceVersion === $sourceVersion) {
                    $fact = $current;
                    break;
                }
            }
        }
        if ($target === null || $fact === null || $fact->sourceVersion !== $sourceVersion
            || ! hash_equals(ProjectModelValueFingerprint::for([
                'value' => $fact->value,
                'unit' => $fact->unit,
            ]), $expectedValueFingerprint)
            || $fact->version - 1 !== $expectedDecisionVersion) {
            throw new EstimateDecisionConflict('project_model_correction_stale');
        }
        $decisionId = 'correction:'.hash('sha256', $idempotencyKey);
        $decision = $this->decisions->apply(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $target->id,
            $value,
            $fact->unit,
            (string) $actorId,
            $reason,
            $decisionId,
        );

        return ['decision_id' => $decision->id, 'reanalysis_requested' => true];
    }
}
