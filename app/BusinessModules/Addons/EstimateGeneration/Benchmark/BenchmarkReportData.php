<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonSerializable;

final readonly class BenchmarkReportData implements JsonSerializable
{
    /**
     * @param  array<string, string>  $modelVersions
     * @param  array<string, array<string, float|int>>  $metrics
     * @param  array<string, mixed>  $breakdowns
     * @param  list<array<string, mixed>>  $caseResults
     * @param  list<array<string, string>>  $fixtureHashes
     */
    public function __construct(
        public int $schemaVersion,
        public string $runId,
        public BenchmarkDatasetType $dataset,
        public string $manifestVersion,
        public string $manifestSha256,
        public string $pipelineVersion,
        public string $adapterId,
        public string $promptVersion,
        public array $modelVersions,
        public array $fixtureHashes,
        public int $caseCount,
        public int $succeededCount,
        public int $failedCount,
        public int $skippedCount,
        public array $metrics,
        public array $breakdowns,
        public array $caseResults,
        public int $durationMs,
        public string $costStatus,
        public ?string $costAmount,
        public ?string $currency,
        public int $unknownCostAttempts,
        public string $generatedAt,
        public string $deterministicFingerprint,
        public float $maxFailureRate,
        public string $failurePolicyVersion,
        public bool $allowUnsupported,
    ) {}

    /** @return array<string, mixed> */
    public function deterministicPayload(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'dataset' => $this->dataset->value,
            'manifest_version' => $this->manifestVersion,
            'manifest_sha256' => $this->manifestSha256,
            'pipeline_version' => $this->pipelineVersion,
            'adapter_id' => $this->adapterId,
            'prompt_version' => $this->promptVersion,
            'model_versions' => $this->modelVersions,
            'fixture_hashes' => $this->fixtureHashes,
            'case_count' => $this->caseCount,
            'succeeded_count' => $this->succeededCount,
            'failed_count' => $this->failedCount,
            'skipped_count' => $this->skippedCount,
            'metrics' => $this->metrics,
            'breakdowns' => $this->breakdowns,
            'case_results' => $this->caseResults,
            'cost_status' => $this->costStatus,
            'cost_amount' => $this->costAmount,
            'currency' => $this->currency,
            'unknown_cost_attempts' => $this->unknownCostAttempts,
            'max_failure_rate' => $this->maxFailureRate,
            'failure_policy_version' => $this->failurePolicyVersion,
            'allow_unsupported' => $this->allowUnsupported,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            ...$this->deterministicPayload(),
            'duration_ms' => $this->durationMs,
            'generated_at' => $this->generatedAt,
            'deterministic_fingerprint' => $this->deterministicFingerprint,
        ];
    }

    public function canonicalJson(): string
    {
        $payload = $this->jsonSerialize();
        self::sortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function passedFailureGate(): bool
    {
        return $this->caseCount > 0 && ($this->failedCount / $this->caseCount) <= $this->maxFailureRate;
    }

    /** @param array<mixed> $value */
    public static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
