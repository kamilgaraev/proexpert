<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class ProjectModelEstimateClarificationAnswerRegistry implements EstimateClarificationAnswerRegistry
{
    public function __construct(
        private ProjectModelRepository $models,
        private int $maxFacts,
    ) {}

    public function answeredKeys(int $organizationId, int $projectId, int $sessionId): array
    {
        $facts = array_values(array_filter(
            $this->models->currentFacts($organizationId, $projectId, $sessionId, null, $this->maxFacts),
            static fn (Fact $fact): bool => is_array($fact->value)
                && is_string($fact->value['question_key'] ?? null)
                && is_string($fact->value['question_fingerprint'] ?? null),
        ));
        if ($facts === []) {
            return [];
        }
        $factIds = array_map(static fn (Fact $fact): string => $fact->id, $facts);
        $selectedByUser = [];
        foreach ($this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $factIds) as $decision) {
            if ($decision->actorType === 'user' && $decision->selectedFactId !== null) {
                $selectedByUser[$decision->selectedFactId] = true;
            }
        }
        $keys = [];
        foreach ($facts as $fact) {
            if (isset($selectedByUser[$fact->id])) {
                $keys[(string) $fact->value['question_key']] = true;
            }
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);

        return $result;
    }
}
