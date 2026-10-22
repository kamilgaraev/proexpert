<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class EloquentEstimateClarificationSource implements EstimateClarificationCatalog, EstimateClarificationSource
{
    public function __construct(
        private ProjectModelRepository $models,
        private ResolveCurrentEstimateClarification $resolver,
        private int $maxFacts,
    ) {}

    public function findCurrent(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        foreach ($this->allCurrent($organizationId, $projectId, $sessionId) as $current) {
            if ($current->question->code === $questionKey) {
                return $current;
            }
        }

        return null;
    }

    public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
    {
        $understanding = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts,
        );

        $questions = $understanding === null ? [] : $this->resolver->resolveAll(
            is_array($understanding['questions'] ?? null) ? array_values($understanding['questions']) : [],
            (string) ($understanding['source_version'] ?? ''),
            $capture['snapshot'],
            $capture['token'],
        );
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->whereKey($sessionId)->first();
        if ($session !== null && $session->status === EstimateGenerationStatus::InputReviewRequired) {
            $composer = $this->resolver->resolveComposer($session->draft_payload ?? [], $session->input_payload ?? [],
                $capture['snapshot'], $capture['token'], [$organizationId, $projectId, $sessionId], (int) $session->state_version);
            if ($composer !== null) {
                $questions[] = $composer;
            }
        }

        return $questions;
    }
}
