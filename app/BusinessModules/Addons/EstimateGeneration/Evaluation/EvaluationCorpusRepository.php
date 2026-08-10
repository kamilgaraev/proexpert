<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

interface EvaluationCorpusRepository
{
    public function addCandidate(EvaluationExample $example): EvaluationExample;

    public function find(int $organizationId, string $sourceVersion): ?EvaluationExample;

    public function transition(
        int $organizationId,
        string $sourceVersion,
        EvaluationReviewDecision $decision,
    ): EvaluationExample;

    /** @return list<EvaluationExample> */
    public function reviewed(int $organizationId): array;
}
