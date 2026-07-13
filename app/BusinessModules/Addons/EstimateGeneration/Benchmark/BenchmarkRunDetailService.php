<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;

final readonly class BenchmarkRunDetailService
{
    private const MAX_FAILURES = 100;

    public function __construct(private BenchmarkPrivateObjectStore $objects) {}

    /** @return array{case_failures: list<array{case_id: string, status: string, failure_code: string}>, metric_deltas: array<string, string>} */
    public function present(EstimateGenerationBenchmarkRun $run): array
    {
        $detail = EstimateGenerationBenchmarkRun::query()
            ->whereKey($run->getKey())
            ->where('organization_id', $run->organization_id)
            ->first([
                'id',
                'organization_id',
                'training_dataset_id',
                'dataset_version',
                'metrics',
                'case_results',
                'case_results_storage_disk',
                'case_results_storage_path',
            ]);
        if (! $detail instanceof EstimateGenerationBenchmarkRun) {
            return ['case_failures' => [], 'metric_deltas' => []];
        }

        return [
            'case_failures' => self::safeCaseFailures($this->caseResults($detail)),
            'metric_deltas' => $this->metricDeltas($detail),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function caseResults(EstimateGenerationBenchmarkRun $run): array
    {
        $value = $run->case_results;
        if (! is_array($value) && $run->case_results_storage_disk === 's3' && is_string($run->case_results_storage_path)) {
            $body = $this->objects->read($run->case_results_storage_path, 64_000_000);
            $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @param list<array<string, mixed>> $cases @return list<array{case_id: string, status: string, failure_code: string}> */
    public static function safeCaseFailures(array $cases): array
    {
        $failures = [];
        foreach ($cases as $case) {
            if (! is_array($case) || ($case['status'] ?? null) === 'success') {
                continue;
            }
            $caseId = self::closedIdentifier($case['case_id'] ?? null, 120);
            $status = self::closedIdentifier($case['status'] ?? null, 40);
            $failureCode = self::closedIdentifier($case['failure_code'] ?? $case['error_code'] ?? 'benchmark_case_failed', 100);
            if ($caseId === null || $status === null || $failureCode === null) {
                continue;
            }
            $failures[] = ['case_id' => $caseId, 'status' => $status, 'failure_code' => $failureCode];
            if (count($failures) >= self::MAX_FAILURES) {
                break;
            }
        }

        return $failures;
    }

    /** @return array<string, string> */
    private function metricDeltas(EstimateGenerationBenchmarkRun $run): array
    {
        $productionVersion = config('estimate-generation.benchmark.production_pipeline_version');
        if (! is_string($productionVersion) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,95}$/', $productionVersion) !== 1) {
            return [];
        }
        $baseline = EstimateGenerationBenchmarkRun::query()
            ->where('organization_id', $run->organization_id)
            ->where('training_dataset_id', $run->training_dataset_id)
            ->where('dataset_version', $run->dataset_version)
            ->where('pipeline_version', $productionVersion)
            ->where('status', EstimateGenerationBenchmarkRun::STATUS_COMPLETED)
            ->whereKeyNot($run->id)
            ->latest('completed_at')
            ->first(['id', 'metrics']);
        if (! $baseline instanceof EstimateGenerationBenchmarkRun || ! is_array($run->metrics) || ! is_array($baseline->metrics)) {
            return [];
        }

        $deltas = [];
        foreach ($run->metrics as $metric => $values) {
            if (! is_string($metric) || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $metric) !== 1 || ! is_array($values)) {
                continue;
            }
            $current = $values['macro'] ?? null;
            $previous = $baseline->metrics[$metric]['macro'] ?? null;
            if (! is_numeric($current) || ! is_numeric($previous)) {
                continue;
            }
            $deltas[$metric] = sprintf('%+.6f', (float) $current - (float) $previous);
        }
        ksort($deltas);

        return array_slice($deltas, 0, 32, true);
    }

    private static function closedIdentifier(mixed $value, int $maxLength): ?string
    {
        return is_string($value)
            && strlen($value) <= $maxLength
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1
                ? $value
                : null;
    }
}
