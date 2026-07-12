<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetTrustPolicy;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BenchmarkRunRepository
{
    private const MAX_JSON_BYTES = 1_048_576;

    private const MAX_INLINE_CASES = 1_000;

    private const MAX_CASE_FIELDS = 32;

    private const SENSITIVE_KEYS = ['password', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'private_key', 'email', 'phone'];

    private const METRICS = [
        'sheet_classification_accuracy', 'room_iou', 'wall_iou', 'opening_f1', 'area_mape',
        'quantity_mape', 'work_recall', 'normative_top1', 'normative_top3', 'cost_mape',
        'technical_success_rate', 'evidenced_applicable_items',
    ];

    public function __construct(
        private readonly TrainingDatasetTrustPolicy $trustPolicy,
        private readonly BenchmarkPrivateObjectStore $objectStore,
    ) {}

    /** @param array<string, mixed> $manifest */
    public function start(EstimateGenerationTrainingDataset $dataset, array $manifest, string $idempotencyKey): EstimateGenerationBenchmarkRun
    {
        $dataset = EstimateGenerationTrainingDataset::query()
            ->whereKey($dataset->getKey())
            ->where('organization_id', $dataset->organization_id)
            ->where('dataset_key', $dataset->dataset_key)
            ->where('version', $dataset->version)
            ->firstOrFail();
        if (! $this->trustPolicy->canBenchmark($dataset)) {
            throw new DomainException('dataset_not_eligible_for_benchmark');
        }
        $this->assertTenantScope($dataset, $manifest);
        $this->boundedJson($manifest['model_versions'] ?? []);
        $expected = $this->manifest($dataset, $manifest);

        return DB::transaction(function () use ($dataset, $expected, $idempotencyKey): EstimateGenerationBenchmarkRun {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [(string) $dataset->organization_id, $idempotencyKey]);
            $existing = EstimateGenerationBenchmarkRun::query()
                ->where('organization_id', $dataset->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof EstimateGenerationBenchmarkRun) {
                if ($this->persistedManifest($existing) !== $expected) {
                    throw new DomainException('benchmark_idempotency_manifest_conflict');
                }

                return $existing;
            }

            return EstimateGenerationBenchmarkRun::query()->create([
                'uuid' => (string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
                ...$expected, 'cost_amount' => '0', 'status' => EstimateGenerationBenchmarkRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $metrics @param array<int, array<string, mixed>>|null $caseResults */
    public function complete(int $organizationId, string $uuid, array $metrics, ?array $caseResults = null, ?string $s3Path = null, ?int $durationMs = null, string $cost = '0', ?int $s3Size = null, ?string $s3Sha256 = null): EstimateGenerationBenchmarkRun
    {
        $this->assertClosedMetrics($metrics);
        $this->boundedJson($metrics);
        if ($durationMs === null || $durationMs < 0 || ! preg_match('/^\d+(?:\.\d{1,8})?$/', $cost)) {
            throw new DomainException('benchmark_completion_values_invalid');
        }
        if (($caseResults === null) === ($s3Path === null)) {
            throw new DomainException('exactly_one_case_results_location_required');
        }
        if ($caseResults !== null) {
            $this->assertInlineResults($caseResults);
        } else {
            $this->assertS3Object($organizationId, (string) $s3Path, $s3Size, $s3Sha256);
        }

        return $this->transition($organizationId, $uuid, EstimateGenerationBenchmarkRun::STATUS_COMPLETED, [
            'metrics' => $metrics, 'case_results' => $caseResults,
            'case_results_storage_disk' => $s3Path === null ? null : 's3', 'case_results_storage_path' => $s3Path,
            'duration_ms' => $durationMs, 'cost_amount' => $cost, 'completed_at' => now(),
        ]);
    }

    public function fail(int $organizationId, string $uuid, string $failureCode): EstimateGenerationBenchmarkRun
    {
        if (trim($failureCode) === '' || strlen($failureCode) > 100) {
            throw new DomainException('benchmark_failure_code_invalid');
        }

        return $this->transition($organizationId, $uuid, EstimateGenerationBenchmarkRun::STATUS_FAILED, [
            'failure_code' => $failureCode, 'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(int $organizationId, string $uuid, string $status, array $attributes): EstimateGenerationBenchmarkRun
    {
        return DB::transaction(function () use ($organizationId, $uuid, $status, $attributes): EstimateGenerationBenchmarkRun {
            $run = EstimateGenerationBenchmarkRun::query()->where('organization_id', $organizationId)->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $run instanceof EstimateGenerationBenchmarkRun) {
                throw (new ModelNotFoundException)->setModel(EstimateGenerationBenchmarkRun::class, [$uuid]);
            }
            if ($run->status !== EstimateGenerationBenchmarkRun::STATUS_RUNNING) {
                if ($run->status === $status) {
                    return $run;
                }
                throw new DomainException('benchmark_run_is_terminal');
            }
            $run->forceFill([...$attributes, 'status' => $status])->save();

            return $run->refresh();
        });
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function manifest(EstimateGenerationTrainingDataset $dataset, array $manifest): array
    {
        foreach (['pipeline_version', 'model_versions', 'normative_version', 'price_version', 'currency'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new DomainException('benchmark_manifest_incomplete');
            }
        }

        return ['organization_id' => (int) $dataset->organization_id, 'training_dataset_id' => (int) $dataset->id,
            'dataset_version' => (int) $dataset->version, 'pipeline_version' => (string) $manifest['pipeline_version'],
            'model_versions' => $manifest['model_versions'], 'normative_version' => (string) $manifest['normative_version'],
            'price_version' => (string) $manifest['price_version'], 'currency' => (string) $manifest['currency']];
    }

    /** @return array<string, mixed> */
    private function persistedManifest(EstimateGenerationBenchmarkRun $run): array
    {
        return ['organization_id' => (int) $run->organization_id, 'training_dataset_id' => (int) $run->training_dataset_id,
            'dataset_version' => (int) $run->dataset_version, 'pipeline_version' => (string) $run->pipeline_version,
            'model_versions' => $run->model_versions, 'normative_version' => (string) $run->normative_version,
            'price_version' => (string) $run->price_version, 'currency' => (string) $run->currency];
    }

    /** @param array<string, mixed> $manifest */
    private function assertTenantScope(EstimateGenerationTrainingDataset $dataset, array $manifest): void
    {
        if ($dataset->scope !== 'organization' || $dataset->organization_id === null || (int) ($manifest['organization_id'] ?? 0) !== (int) $dataset->organization_id) {
            throw new DomainException('benchmark_tenant_scope_mismatch');
        }
    }

    /** @param array<int, array<string, mixed>> $results */
    private function assertInlineResults(array $results): void
    {
        if ($results === [] || count($results) > self::MAX_INLINE_CASES || ! array_is_list($results)) {
            throw new DomainException('benchmark_case_results_invalid');
        }
        foreach ($results as $case) {
            if (! is_array($case) || count($case) > self::MAX_CASE_FIELDS) {
                throw new DomainException('benchmark_case_results_invalid');
            }
            $this->assertNoSensitiveKeys($case);
        }
        $this->boundedJson($results);
    }

    /** @param array<string, mixed> $value */
    private function assertNoSensitiveKeys(array $value): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                throw new DomainException('benchmark_sensitive_case_result_rejected');
            }
            if (is_array($nested)) {
                $this->assertNoSensitiveKeys($nested);
            }
        }
    }

    private function assertS3Object(int $organizationId, string $path, ?int $size, ?string $sha256): void
    {
        if (! str_starts_with($path, "org-{$organizationId}/estimate-generation/benchmarks/") || $size === null || $size < 1 || $size > 64_000_000 || ! preg_match('/^[a-f0-9]{64}$/', (string) $sha256)) {
            throw new DomainException('benchmark_results_object_invalid');
        }
        $contents = $this->objectStore->read($path, $size);
        if (strlen($contents) !== $size || ! hash_equals((string) $sha256, hash('sha256', $contents))) {
            throw new DomainException('benchmark_results_object_integrity_mismatch');
        }
    }

    private function boundedJson(mixed $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new DomainException('benchmark_payload_too_large');
        }
    }

    /** @param array<string, mixed> $metrics */
    private function assertClosedMetrics(array $metrics): void
    {
        if ($metrics === []) {
            throw new DomainException('benchmark_metrics_required');
        }
        foreach ($metrics as $name => $values) {
            if (! in_array($name, self::METRICS, true) || ! is_array($values) || $values === []) {
                throw new DomainException('benchmark_metric_not_allowed');
            }
            foreach ($values as $key => $value) {
                if (! in_array($key, ['macro', 'micro', 'numerator', 'denominator', 'overflow_count'], true) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    throw new DomainException('benchmark_metric_value_invalid');
                }
            }
        }
    }
}
