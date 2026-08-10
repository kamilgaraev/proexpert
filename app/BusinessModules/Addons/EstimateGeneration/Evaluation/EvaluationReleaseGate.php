<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use DomainException;

final readonly class EvaluationReleaseGate
{
    public function __construct(private EvaluationCorpus $corpus) {}

    /** @return non-empty-list<EvaluationExample> */
    public function reviewedCorpus(int $organizationId): array
    {
        $examples = $this->corpus->listReviewed($organizationId);
        if ($examples === []) {
            throw new DomainException('Evaluation release corpus has no reviewed examples.');
        }

        return $examples;
    }
}
