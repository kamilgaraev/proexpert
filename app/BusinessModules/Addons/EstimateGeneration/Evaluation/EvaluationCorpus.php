<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use InvalidArgumentException;

final readonly class EvaluationCorpus
{
    public function __construct(
        private EvaluationEstimateRowNormalizer $rows,
        private EvaluationCorpusRepository $repository,
    ) {}

    public function addCandidate(
        int $organizationId,
        string $sourceVersion,
        array $expectedFacts,
        array $expectedDecisions,
        array $expectedQuantities,
        array $expectedEstimateRows,
        array $contractVersions,
    ): EvaluationExample {
        return $this->repository->addCandidate(new EvaluationExample(
            organizationId: $organizationId,
            sourceVersion: $sourceVersion,
            expectedFacts: $expectedFacts,
            expectedDecisions: $expectedDecisions,
            expectedQuantities: $expectedQuantities,
            expectedEstimateRows: $this->normalizeRows($expectedEstimateRows),
            contractVersions: $contractVersions,
            trustStatus: EvaluationExampleTrust::Candidate,
            split: $this->split($sourceVersion),
        ));
    }

    public function review(
        int $organizationId,
        string $sourceVersion,
        EvaluationReviewDecision $decision,
    ): EvaluationExample {
        if ($decision->trustStatus !== EvaluationExampleTrust::Reviewed) {
            throw new InvalidArgumentException('Evaluation review decision is invalid.');
        }

        return $this->repository->transition($organizationId, $sourceVersion, $decision);
    }

    public function reject(
        int $organizationId,
        string $sourceVersion,
        EvaluationReviewDecision $decision,
    ): EvaluationExample {
        if ($decision->trustStatus !== EvaluationExampleTrust::Rejected) {
            throw new InvalidArgumentException('Evaluation rejection decision is invalid.');
        }

        return $this->repository->transition($organizationId, $sourceVersion, $decision);
    }

    public function find(int $organizationId, string $sourceVersion): ?EvaluationExample
    {
        return $this->repository->find($organizationId, $sourceVersion);
    }

    /** @return list<EvaluationExample> */
    public function listReviewed(int $organizationId): array
    {
        return $this->repository->reviewed($organizationId);
    }

    private function normalizeRows(array $rows): array
    {
        return array_values(array_map(function (mixed $row): array {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Evaluation estimate row is invalid.');
            }
            $normalized = $this->rows->normalize($row);
            unset($normalized['raw_payload']);

            return $normalized;
        }, $rows));
    }

    private function split(string $sourceVersion): string
    {
        if (preg_match('/\Asha256:([a-f0-9]{64})\z/', $sourceVersion, $matches) !== 1) {
            throw new InvalidArgumentException('Evaluation source version is invalid.');
        }

        return hexdec(substr($matches[1], -2)) % 5 === 0 ? 'test' : 'development';
    }
}
