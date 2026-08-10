<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use DomainException;
use InvalidArgumentException;

final class EvaluationCorpus
{
    private array $examples = [];

    public function __construct(private readonly EvaluationEstimateRowNormalizer $rows) {}

    public function addCandidate(
        string $sourceVersion,
        array $expectedFacts,
        array $expectedDecisions,
        array $expectedQuantities,
        array $expectedEstimateRows,
        array $contractVersions,
    ): EvaluationExample {
        $example = new EvaluationExample(
            sourceVersion: $sourceVersion,
            expectedFacts: $expectedFacts,
            expectedDecisions: $expectedDecisions,
            expectedQuantities: $expectedQuantities,
            expectedEstimateRows: $this->normalizeRows($expectedEstimateRows),
            contractVersions: $contractVersions,
            trustStatus: EvaluationExampleTrust::Candidate,
            split: $this->split($sourceVersion),
        );
        $existing = $this->examples[$sourceVersion] ?? null;
        if ($existing instanceof EvaluationExample) {
            if (! hash_equals($existing->fingerprint(), $example->fingerprint())) {
                throw new DomainException('Evaluation source version collision.');
            }

            return $existing;
        }

        $this->examples[$sourceVersion] = $example;

        return $example;
    }

    public function review(string $sourceVersion): EvaluationExample
    {
        return $this->changeTrust($sourceVersion, EvaluationExampleTrust::Reviewed);
    }

    public function reject(string $sourceVersion): EvaluationExample
    {
        return $this->changeTrust($sourceVersion, EvaluationExampleTrust::Rejected);
    }

    public function listReviewed(): array
    {
        return array_values(array_filter(
            $this->examples,
            static fn (EvaluationExample $example): bool => $example->trustStatus === EvaluationExampleTrust::Reviewed,
        ));
    }

    private function changeTrust(string $sourceVersion, EvaluationExampleTrust $trust): EvaluationExample
    {
        $example = $this->examples[$sourceVersion] ?? null;
        if (! $example instanceof EvaluationExample) {
            throw new InvalidArgumentException('Evaluation example was not found.');
        }

        return $this->examples[$sourceVersion] = $example->withTrust($trust);
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
