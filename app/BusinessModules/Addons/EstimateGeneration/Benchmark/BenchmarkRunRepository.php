<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetTrustPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BenchmarkRunRepository
{
    private const MAX_JSON_BYTES = 1_048_576;

    private const METRICS = [
        'sheet_classification_accuracy', 'room_iou', 'wall_iou', 'opening_f1', 'area_mape',
        'quantity_mape', 'work_recall', 'normative_top1', 'normative_top3', 'cost_mape',
        'technical_success_rate', 'evidenced_applicable_items',
    ];

    public function __construct(private readonly TrainingDatasetTrustPolicy $trustPolicy) {}

    /** @param array<string, mixed> $manifest */
    public function start(EstimateGenerationTrainingDataset $dataset, array $manifest, string $idempotencyKey): EstimateGenerationBenchmarkRun
    {
        if (! $this->trustPolicy->canBenchmark($dataset)) {
            throw new DomainException('dataset_not_eligible_for_benchmark');
        }

        $this->assertTenantScope($dataset, $manifest);
        $this->boundedJson($manifest['model_versions'] ?? []);

        return DB::transaction(function () use ($dataset, $manifest, $idempotencyKey): EstimateGenerationBenchmarkRun {
            $existing = EstimateGenerationBenchmarkRun::query()
                ->where('organization_id', $dataset->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EstimateGenerationBenchmarkRun) {
                return $existing;
            }

            return EstimateGenerationBenchmarkRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $dataset->organization_id,
                'training_dataset_id' => $dataset->id,
                'dataset_version' => $dataset->version,
                'pipeline_version' => $manifest['pipeline_version'],
                'model_versions' => $manifest['model_versions'],
                'normative_version' => $manifest['normative_version'],
                'price_version' => $manifest['price_version'],
                'cost_amount' => '0',
                'currency' => $manifest['currency'],
                'status' => EstimateGenerationBenchmarkRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $metrics @param array<string, mixed>|null $caseResults */
    public function complete(string $uuid, array $metrics, ?array $caseResults = null, ?string $s3Path = null, ?int $durationMs = null, string $cost = '0'): EstimateGenerationBenchmarkRun
    {
        $this->assertClosedMetrics($metrics);
        $this->boundedJson($metrics);
        if ($caseResults !== null) {
            $this->boundedJson($caseResults);
        }
        if (($caseResults === null) === ($s3Path === null)) {
            throw new DomainException('exactly_one_case_results_location_required');
        }

        return $this->transition($uuid, EstimateGenerationBenchmarkRun::STATUS_COMPLETED, [
            'metrics' => $metrics,
            'case_results' => $caseResults,
            'case_results_storage_disk' => $s3Path === null ? null : 's3',
            'case_results_storage_path' => $s3Path,
            'duration_ms' => $durationMs,
            'cost_amount' => $cost,
            'completed_at' => now(),
        ]);
    }

    public function fail(string $uuid, string $failureCode): EstimateGenerationBenchmarkRun
    {
        return $this->transition($uuid, EstimateGenerationBenchmarkRun::STATUS_FAILED, [
            'failure_code' => $failureCode,
            'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(string $uuid, string $status, array $attributes): EstimateGenerationBenchmarkRun
    {
        return DB::transaction(function () use ($uuid, $status, $attributes): EstimateGenerationBenchmarkRun {
            $run = EstimateGenerationBenchmarkRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $storagePath = $attributes['case_results_storage_path'] ?? null;
            if (is_string($storagePath) && ! str_starts_with($storagePath, "org-{$run->organization_id}/")) {
                throw new DomainException('benchmark_results_tenant_scope_mismatch');
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

    /** @param array<string, mixed> $manifest */
    private function assertTenantScope(EstimateGenerationTrainingDataset $dataset, array $manifest): void
    {
        if ($dataset->scope !== 'organization' || $dataset->organization_id === null
            || (int) ($manifest['organization_id'] ?? 0) !== (int) $dataset->organization_id) {
            throw new DomainException('benchmark_tenant_scope_mismatch');
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
        foreach ($metrics as $name => $values) {
            if (! in_array($name, self::METRICS, true) || ! is_array($values)) {
                throw new DomainException('benchmark_metric_not_allowed');
            }
            foreach ($values as $key => $value) {
                if (! in_array($key, ['macro', 'micro', 'numerator', 'denominator', 'overflow_count'], true)
                    || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    throw new DomainException('benchmark_metric_value_invalid');
                }
            }
        }
    }
}
