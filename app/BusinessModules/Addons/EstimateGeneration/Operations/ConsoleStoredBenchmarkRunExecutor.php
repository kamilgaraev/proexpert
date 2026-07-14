<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use DomainException;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

final readonly class ConsoleStoredBenchmarkRunExecutor implements StoredBenchmarkRunExecutor
{
    public function __construct(
        private Kernel $console,
        private BenchmarkRunRepository $runs,
        private BenchmarkImmutableObjectStore $objects,
        private BenchmarkAdapterRegistry $adapters,
    ) {}

    public function execute(int $runId, string $idempotencyKey): void
    {
        $run = EstimateGenerationBenchmarkRun::query()->whereKey($runId)->where('idempotency_key', $idempotencyKey)->first();
        if (! $run instanceof EstimateGenerationBenchmarkRun || $run->status !== EstimateGenerationBenchmarkRun::STATUS_RUNNING || ! is_array($run->execution_snapshot)) {
            return;
        }
        $snapshot = BenchmarkExecutionSnapshot::fromArray($run->execution_snapshot);
        $dataset = EstimateGenerationTrainingDataset::query()
            ->whereKey((int) $snapshot->get('dataset_id'))
            ->where('organization_id', (int) $snapshot->get('organization_id'))
            ->where('version', (int) $snapshot->get('dataset_version'))
            ->first();
        $datasetManifest = $dataset instanceof EstimateGenerationTrainingDataset && is_array($dataset->stats)
            ? ($dataset->stats['benchmark_manifest'] ?? null)
            : null;
        if (! $dataset instanceof EstimateGenerationTrainingDataset || ! is_array($datasetManifest)) {
            $this->runs->fail((int) $run->organization_id, (string) $run->uuid, 'benchmark_execution_dataset_mismatch', 'benchmark_execution_failed');

            return;
        }
        try {
            $snapshot->assertDataset(
                (int) $dataset->organization_id,
                (int) $dataset->id,
                (string) $dataset->dataset_type,
                (int) $dataset->version,
                (string) ($datasetManifest['dataset_content_hash'] ?? ''),
            );
        } catch (DomainException) {
            $this->runs->fail((int) $run->organization_id, (string) $run->uuid, 'benchmark_execution_dataset_mismatch', 'benchmark_execution_failed');

            return;
        }
        if (! hash_equals((string) $snapshot->get('manifest_locator'), (string) ($datasetManifest['locator'] ?? ''))
            || ! hash_equals((string) $snapshot->get('manifest_sha256'), (string) ($datasetManifest['sha256'] ?? ''))
            || ! $this->adapters->has((string) $snapshot->get('adapter_id'))) {
            $this->runs->fail((int) $run->organization_id, (string) $run->uuid, 'benchmark_execution_snapshot_mismatch', 'benchmark_execution_failed');

            return;
        }

        $arguments = [
            '--dataset' => (string) $snapshot->get('dataset_type'),
            '--format' => 'json',
            '--adapter' => (string) $snapshot->get('adapter_id'),
            '--pipeline-version' => (string) $snapshot->get('pipeline_version'),
            '--prompt-version' => (string) $snapshot->get('prompt_version'),
            '--manifest' => (string) $snapshot->get('manifest_locator'),
            '--manifest-sha256' => (string) $snapshot->get('manifest_sha256'),
            '--organization-id' => (string) $snapshot->get('organization_id'),
            '--settings-snapshot-id' => (string) $snapshot->get('settings_snapshot_id'),
            '--settings-snapshot-version' => (string) $snapshot->get('settings_snapshot_version'),
            '--normative-version' => (string) $snapshot->get('normative_version'),
            '--price-version' => (string) $snapshot->get('price_version'),
            '--case-timeout-ms' => (string) max(100, min(3_600_000, (int) config('estimate-generation.benchmark.admin_case_timeout_ms', 300000))),
            '--max-failure-rate' => '0',
            '--failure-policy-version' => 'strict-zero:v1',
        ];
        if (app()->environment('production')) {
            $arguments['--output'] = sprintf(
                's3://org-%d/estimate-generation/benchmarks/%s/{sha256}.json',
                (int) $run->organization_id,
                (string) $run->uuid,
            );
            $arguments['--emit-json'] = true;
        }

        $exitCode = $this->console->call('estimate-generation:benchmark', $arguments);
        $output = trim($this->console->output());
        if ($exitCode !== 0) {
            $code = preg_match('/^[a-z0-9_.:-]{1,100}$/', $output) === 1 ? $output : 'benchmark_failed';
            $this->runs->fail((int) $run->organization_id, (string) $run->uuid, $code, 'benchmark_execution_failed');

            return;
        }

        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($report) || ! is_array($report['metrics'] ?? null) || ! is_array($report['case_results'] ?? null)) {
            throw new RuntimeException('benchmark_report_invalid');
        }
        try {
            $this->assertReportMatches($snapshot, $report);
        } catch (DomainException) {
            $this->runs->fail((int) $run->organization_id, (string) $run->uuid, 'benchmark_execution_report_mismatch', 'benchmark_execution_failed');

            return;
        }
        $caseResults = $report['case_results'];
        $durationMs = filter_var($report['duration_ms'] ?? null, FILTER_VALIDATE_INT);
        $cost = is_string($report['cost_amount'] ?? null) ? $report['cost_amount'] : '0';
        if (! is_int($durationMs)) {
            throw new RuntimeException('benchmark_report_duration_invalid');
        }

        if (app()->environment('production')) {
            $encodedCases = json_encode($caseResults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $path = sprintf(
                'org-%d/estimate-generation/benchmarks/%s/%s.json',
                (int) $run->organization_id,
                (string) $run->uuid,
                hash('sha256', $encodedCases),
            );
            $this->objects->putImmutable($path, $encodedCases, 'application/json');
            $this->runs->complete(
                (int) $run->organization_id,
                (string) $run->uuid,
                $report['metrics'],
                s3Path: $path,
                durationMs: $durationMs,
                cost: $cost,
            );

            return;
        }

        $this->runs->complete(
            (int) $run->organization_id,
            (string) $run->uuid,
            $report['metrics'],
            caseResults: $caseResults,
            durationMs: $durationMs,
            cost: $cost,
        );
    }

    /** @param array<string, mixed> $report */
    private function assertReportMatches(BenchmarkExecutionSnapshot $snapshot, array $report): void
    {
        $snapshot->assertReport($report);
    }
}
