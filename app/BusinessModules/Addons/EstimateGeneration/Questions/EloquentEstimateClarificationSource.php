<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

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
        if ($understanding === null) {
            return [];
        }
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts,
        );

        return $this->resolver->resolveAll(
            is_array($understanding['questions'] ?? null) ? array_values($understanding['questions']) : [],
            (string) ($understanding['source_version'] ?? ''),
            $capture['snapshot'],
            $capture['token'],
        );
    }
}
