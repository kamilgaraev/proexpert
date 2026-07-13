<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

final readonly class ConsoleStoredBenchmarkRunExecutor implements StoredBenchmarkRunExecutor
{
    public function __construct(
        private Kernel $console,
        private BenchmarkRunRepository $runs,
        private BenchmarkImmutableObjectStore $objects,
    ) {}

    public function execute(
        int $runId,
        string $datasetType,
        string $adapterId,
        string $promptVersion,
        ?string $manifestLocator,
    ): void {
        $run = EstimateGenerationBenchmarkRun::query()->find($runId);
        if (! $run instanceof EstimateGenerationBenchmarkRun || $run->status !== EstimateGenerationBenchmarkRun::STATUS_RUNNING) {
            return;
        }

        $arguments = [
            '--dataset' => $datasetType,
            '--format' => 'json',
            '--adapter' => $adapterId,
            '--pipeline-version' => (string) $run->pipeline_version,
            '--prompt-version' => $promptVersion,
            '--case-timeout-ms' => (string) max(100, min(3_600_000, (int) config('estimate-generation.benchmark.admin_case_timeout_ms', 300000))),
            '--max-failure-rate' => '0',
            '--failure-policy-version' => 'strict-zero:v1',
        ];
        if ($manifestLocator !== null && $manifestLocator !== '') {
            $arguments['--manifest'] = $manifestLocator;
        }
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
}
