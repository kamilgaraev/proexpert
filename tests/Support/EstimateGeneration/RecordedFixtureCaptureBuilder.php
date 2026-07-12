<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPort;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortRequestHasher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use InvalidArgumentException;

final class RecordedFixtureCaptureBuilder
{
    public function geometryDependency(BenchmarkPredictionCaseData $case, RecordedPort $port, string $source): string
    {
        $this->source($case, $source);

        return RecordedPortRequestHasher::geometry($case, $port);
    }

    public function plannerDependency(array $buildingModel, array $quantities, array $evidence): string
    {
        $this->safe([$buildingModel, $quantities, $evidence]);

        return RecordedPortRequestHasher::planner($buildingModel, $quantities, $evidence);
    }

    public function rerankerDependency(
        WorkIntentData $intent,
        NormativeCandidateDecisionContextData $context,
        NormativeCandidateSetData $set,
    ): string {
        return RecordedPortRequestHasher::reranker($intent, $context, $set);
    }

    public function envelope(array $metadata, array $payload, string $dependency, string $sourceSha256): array
    {
        $this->safe([$metadata, $payload]);
        if (($metadata['privacy_result'] ?? null) !== 'passed'
            || ($metadata['approval_kind'] ?? null) !== 'maintainer_code_review'
            || ! hash_equals($sourceSha256, (string) ($metadata['source_sha256'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/D', $dependency) !== 1) {
            throw new InvalidArgumentException('capture_metadata_invalid');
        }
        $canonical = $this->canonical($payload);

        return [...$metadata, 'input_dependency_sha256' => $dependency, 'payload' => $payload,
            'payload_sha256' => hash('sha256', $canonical)];
    }

    public function inventory(array $caseIds, array $sourceHashes): array
    {
        if (count($caseIds) !== count(array_unique($caseIds)) || count($caseIds) !== count($sourceHashes)) {
            throw new InvalidArgumentException('capture_inventory_invalid');
        }
        $rows = [];
        foreach ($caseIds as $index => $caseId) {
            $rows[] = ['case_id' => $caseId, 'source_sha256' => $sourceHashes[$index]];
        }
        usort($rows, static fn (array $a, array $b): int => $a['case_id'] <=> $b['case_id']);

        return ['schema_version' => 'recorded-fixture-inventory:v1', 'cases' => $rows];
    }

    private function source(BenchmarkPredictionCaseData $case, string $source): void
    {
        if (! hash_equals($case->inputSha256, hash('sha256', $source))) {
            throw new InvalidArgumentException('capture_source_mismatch');
        }
    }

    private function safe(array $value): void
    {
        $forbidden = ['expected', 'labels', 'prediction', 'metrics', 'readiness', 'total_cost', 'total_price'];
        array_walk_recursive($value, static function (mixed $_, mixed $key) use ($forbidden): void {
            if (is_string($key) && (in_array(strtolower($key), $forbidden, true)
                || str_starts_with(strtolower($key), 'expected_') || str_starts_with(strtolower($key), 'final_'))) {
                throw new InvalidArgumentException('capture_forbidden_field');
            }
        });
    }

    private function canonical(array $payload): string
    {
        $sort = function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
