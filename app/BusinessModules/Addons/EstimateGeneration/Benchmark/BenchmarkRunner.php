<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricResultData;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class BenchmarkRunner
{
    /** @var Closure(): float */
    private Closure $clock;

    public function __construct(
        private MetricRegistry $metrics,
        private BenchmarkCaseExecutor $executor,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null
            ? static fn (): float => microtime(true) * 1000
            : Closure::fromCallable($clock);
    }

    public function run(
        BenchmarkManifest $manifest,
        BenchmarkDatasetType $dataset,
        BenchmarkPipelineAdapter $adapter,
        BenchmarkRunOptions $options,
        ?BenchmarkObjectReader $objects = null,
        string $manifestReference = 'repository:v1',
    ): BenchmarkReportData {
        $objects ??= new LocalBenchmarkObjectReader;
        $cases = $manifest->casesFor($dataset);
        if ($cases === []) {
            throw new BenchmarkManifestException('dataset_has_no_cases');
        }
        $started = ($this->clock)();
        $caseResults = [];
        $metricResults = [];
        $modelVersions = [];
        $cost = new DecimalAmount;
        $currency = null;
        $unknownCostAttempts = 0;

        foreach ($cases as $case) {
            [$caseResult, $calculated, $models, $knownCost, $knownCurrency] = $this->runCase(
                $case, $adapter, $options, $objects, $manifestReference,
            );
            $caseResults[] = $caseResult;
            $metricResults[$case->id] = $calculated;
            foreach ($models as $model => $version) {
                if (isset($modelVersions[$model]) && $modelVersions[$model] !== $version) {
                    throw new BenchmarkManifestException('model_version_conflict');
                }
                $modelVersions[$model] = $version;
            }
            if ($caseResult['status'] === 'unsupported') {
                continue;
            }
            if ($knownCost === null || $knownCurrency === null) {
                $unknownCostAttempts++;
            } elseif ($currency !== null && $currency !== $knownCurrency) {
                throw new BenchmarkManifestException('mixed_cost_currency');
            } else {
                $currency = $knownCurrency;
                $cost->add($knownCost);
            }
        }
        ksort($modelVersions, SORT_STRING);
        $duration = max(0, (int) round(($this->clock)() - $started));
        $succeeded = count(array_filter($caseResults, static fn (array $result): bool => $result['status'] === 'success'));
        $failed = count(array_filter($caseResults, static fn (array $result): bool => $result['status'] === 'technical_failure'));
        $skipped = count(array_filter($caseResults, static fn (array $result): bool => $result['status'] === 'unsupported'));
        $attempted = $succeeded + $failed;
        $statusByCase = array_column($caseResults, 'status', 'case_id');
        $attemptedMetrics = array_filter(
            $metricResults,
            static fn (array $metrics, string $caseId): bool => ($statusByCase[$caseId] ?? null) !== 'unsupported',
            ARRAY_FILTER_USE_BOTH,
        );
        $aggregates = $this->aggregate($attemptedMetrics);
        $breakdowns = $this->breakdowns($cases, $attemptedMetrics);
        $fixtureHashes = array_map(static fn (BenchmarkCaseData $case): array => [
            'case_id' => $case->id,
            'input_sha256' => $case->inputSha256,
            'expected_sha256' => $case->expectedSha256,
        ], $cases);
        $knownCost = $cost->value();
        $costStatus = $attempted === 0 || $unknownCostAttempts === $attempted
            ? 'unknown'
            : ($unknownCostAttempts > 0 ? 'partial' : 'known');
        $knownCostAmount = $costStatus === 'unknown' ? null : $knownCost;
        $knownCurrency = $costStatus === 'unknown' ? null : $currency;
        $stable = [
            $manifest->manifestSha256,
            $dataset->value,
            $options->pipelineVersion,
            $options->promptVersion,
            $options->failurePolicyVersion,
            $options->maxFailureRate,
            $options->allowUnsupported,
            $adapter->id(),
            $modelVersions,
            $fixtureHashes,
            $aggregates,
            $breakdowns,
            $caseResults,
            $costStatus,
            $knownCostAmount,
            $knownCurrency,
            $unknownCostAttempts,
        ];
        BenchmarkReportData::sortRecursive($stable);

        return new BenchmarkReportData(
            1,
            $this->uuidV4(),
            $dataset,
            $manifest->manifestVersion,
            $manifest->manifestSha256,
            $options->pipelineVersion,
            $adapter->id(),
            $options->promptVersion,
            $modelVersions,
            $fixtureHashes,
            count($cases),
            $attempted,
            $succeeded,
            $failed,
            $skipped,
            $aggregates,
            $breakdowns,
            $caseResults,
            $duration,
            $costStatus,
            $knownCostAmount,
            $knownCurrency,
            $unknownCostAttempts,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            $options->maxFailureRate,
            $options->failurePolicyVersion,
            $options->allowUnsupported,
        );
    }

    /** @return array{array<string, mixed>, array<string, MetricResultData>, array<string, string>, ?string, ?string} */
    private function runCase(
        BenchmarkCaseData $case,
        BenchmarkPipelineAdapter $adapter,
        BenchmarkRunOptions $options,
        BenchmarkObjectReader $objects,
        string $manifestReference,
    ): array {
        try {
            $expectedPayload = json_decode($objects->read($case, 'expected', 4_000_000), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($expectedPayload)) {
                throw new BenchmarkContractException('expected_contract_invalid');
            }
            $expected = BenchmarkExpectedContract::expected($expectedPayload, $case->expectedModelSchemaVersion);
            $started = ($this->clock)();
            $result = $this->executor->execute(new BenchmarkCaseExecutionRequest(
                $manifestReference, $case->id, $adapter->id(), $options->caseTimeoutMs,
            ), $case, $adapter);
            if ($result->status === 'unsupported' && (! $options->allowUnsupported
                || ! in_array('unsupported_conversion', $case->tags, true)
                || ! in_array('descriptor_validation', $case->allowedCapabilities, true))) {
                $result = BenchmarkPipelineResultData::technicalFailure('unsupported_not_allowed');
            }
            $elapsed = max(0, (int) round(($this->clock)() - $started));
            if ($elapsed > $options->caseTimeoutMs) {
                $result = BenchmarkPipelineResultData::technicalFailure('case_timeout');
            }
            $success = $result->status === 'success';
            try {
                $prediction = $success ? BenchmarkExpectedContract::prediction($result->prediction) : [];
                $metrics = $this->metrics->calculate($expected, $prediction, $success);
            } catch (Throwable) {
                $result = BenchmarkPipelineResultData::technicalFailure('prediction_contract_invalid');
                $metrics = $this->metrics->calculate($expected, [], false);
            }

            return [[
                'case_id' => $case->id,
                'source_type' => $case->sourceType->value,
                'tags' => $case->tags,
                'status' => $result->status,
                'failure_code' => $result->failureCode,
                'metrics' => array_map(static fn (MetricResultData $metric): float => $metric->value, $metrics),
            ], $metrics, $result->modelVersions, $result->costAmount, $result->currency];
        } catch (Throwable $exception) {
            $expected = isset($expected) && is_array($expected) ? $expected : $this->emptyExpected();
            $metrics = $this->metrics->calculate($expected, [], false);
            $code = $exception instanceof BenchmarkManifestException ? $exception->reason : 'pipeline_exception';
            if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code)) {
                $code = 'pipeline_exception';
            }

            return [[
                'case_id' => $case->id,
                'source_type' => $case->sourceType->value,
                'tags' => $case->tags,
                'status' => 'technical_failure',
                'failure_code' => $code,
                'metrics' => array_map(static fn (MetricResultData $metric): float => $metric->value, $metrics),
            ], $metrics, [], null, null];
        }
    }

    /** @param array<string, array<string, MetricResultData>> $results @return array<string, array<string, float|int>> */
    private function aggregate(array $results): array
    {
        $aggregates = [];
        foreach ($this->metrics->names() as $name) {
            $items = array_map(static fn (array $case): MetricResultData => $case[$name], $results);
            if ($items === []) {
                $aggregates[$name] = ['macro' => 0.0, 'micro' => 0.0, 'denominator' => 0, 'raw_error' => 0.0, 'overflow_count' => 0];

                continue;
            }
            $denominator = array_sum(array_map(static fn (MetricResultData $metric): int => $metric->denominator, $items));
            $numerator = array_sum(array_map(static fn (MetricResultData $metric): float => $metric->numerator, $items));
            $isMape = str_ends_with($name, '_mape');
            $microRaw = $denominator === 0 ? 0.0 : $numerator / $denominator;
            $micro = $isMape ? 1.0 - min(1.0, $microRaw) : ($denominator === 0 ? 1.0 : $numerator / $denominator);
            $aggregates[$name] = [
                'macro' => array_sum(array_map(static fn (MetricResultData $metric): float => $metric->value, $items)) / count($items),
                'micro' => $micro,
                'denominator' => $denominator,
                'raw_error' => $isMape ? $microRaw : 0.0,
                'overflow_count' => count(array_filter($items, static fn (MetricResultData $metric): bool => $metric->overflow)),
            ];
        }

        return $aggregates;
    }

    /** @param list<BenchmarkCaseData> $cases @param array<string, array<string, MetricResultData>> $results @return array<string, mixed> */
    private function breakdowns(array $cases, array $results): array
    {
        $groups = ['per_source' => [], 'per_tag' => []];
        foreach ($cases as $case) {
            if (! isset($results[$case->id])) {
                continue;
            }
            $groups['per_source'][$case->sourceType->value][$case->id] = $results[$case->id];
            foreach ($case->tags as $tag) {
                $groups['per_tag'][$tag][$case->id] = $results[$case->id];
            }
        }
        foreach ($groups as &$group) {
            ksort($group, SORT_STRING);
            foreach ($group as $key => $items) {
                $group[$key] = $this->aggregate($items);
            }
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    private function emptyExpected(): array
    {
        return [
            'sheet_type' => 'unknown', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
            'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [],
            'costs' => [], 'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
        ];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
