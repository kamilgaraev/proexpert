<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCommandException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RegisteredBenchmarkManifestRepository;
use Illuminate\Console\Command;
use Throwable;

final class RunEstimateGenerationBenchmarkCommand extends Command
{
    protected $signature = 'estimate-generation:benchmark
        {--dataset=regression : development|regression|acceptance}
        {--format=json : json|table}
        {--output= : Relative path under the benchmark output root}
        {--manifest= : Relative two-case replay manifest under the fixture root}
        {--adapter= : Explicit registered adapter}
        {--pipeline-version= : Version of the evaluated pipeline}
        {--prompt-version=none:v1 : Version of the evaluated prompt set}
        {--case-timeout-ms=300000 : Per-case timeout}
        {--max-failure-rate=0 : Maximum technical failure ratio}
        {--failure-policy-version=strict-zero:v1 : Versioned failure policy}
        {--allow-unsupported : Allow only manifest-declared unsupported cases}';

    protected $description = 'Запускает изолированный benchmark AI-сметчика без доступа к рабочим сессиям.';

    /** @var callable(): string */
    private $environment;

    /** @var callable(string): ?string */
    private $env;

    public function __construct(
        private readonly BenchmarkRunner $runner,
        private readonly BenchmarkAdapterRegistry $adapters,
        private readonly string $repositoryManifestPath,
        private readonly string $fixtureRoot,
        private readonly string $outputRoot,
        ?callable $environment = null,
        ?callable $env = null,
        private readonly ?string $acceptanceManifestLocator = null,
        private readonly ?int $acceptanceOrganizationId = null,
        private readonly ?AcceptanceBenchmarkCorpusLoader $acceptanceLoader = null,
        private readonly ?RegisteredBenchmarkManifestRepository $registeredManifests = null,
    ) {
        parent::__construct();
        $this->environment = $environment ?? static fn (): string => (string) app()->environment();
        $this->env = $env ?? static fn (string $key): ?string => (($value = getenv($key)) === false ? null : $value);
    }

    public function handle(): int
    {
        try {
            $dataset = BenchmarkDatasetType::tryFrom((string) $this->option('dataset'))
                ?? throw new BenchmarkCommandException('dataset_invalid');
            $format = (string) $this->option('format');
            if (! in_array($format, ['json', 'table'], true)) {
                throw new BenchmarkCommandException('format_invalid');
            }
            $corpus = $this->corpus($dataset);
            $adapter = $this->adapters->get((string) $this->option('adapter'));
            $report = $this->runner->run($corpus->manifest, $dataset, $adapter, new BenchmarkRunOptions(
                (string) $this->option('pipeline-version'),
                (string) $this->option('prompt-version'),
                (int) $this->option('case-timeout-ms'),
                $this->failureRate(),
                (string) $this->option('failure-policy-version'),
                (bool) $this->option('allow-unsupported'),
            ), $corpus->objects, $corpus->executionReference);
            $rendered = $format === 'json' ? $report->canonicalJson() : $this->tablePayload($report);
            $output = $this->option('output');
            if (is_string($output) && $output !== '') {
                $this->writeOutput($output, $rendered);
            } else {
                $this->line($rendered);
            }

            return $report->passedFailureGate() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($this->safeCode($exception));

            return self::FAILURE;
        }
    }

    private function corpus(BenchmarkDatasetType $dataset): BenchmarkCorpus
    {
        if ($dataset !== BenchmarkDatasetType::Acceptance) {
            $manifestPath = $this->repositoryManifestPath;
            $requireAllSourceTypes = true;
            $manifest = $this->option('manifest');
            if (is_string($manifest) && $manifest !== '') {
                $registered = $this->registeredManifests?->byLocator($manifest)
                    ?? throw new BenchmarkCommandException('manifest_not_registered');

                return new BenchmarkCorpus($registered['manifest'],
                    new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader,
                    $registered['reference']);
            }

            return new BenchmarkCorpus(
                BenchmarkManifest::fromFile($manifestPath, $this->fixtureRoot, $requireAllSourceTypes),
                new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader,
                $requireAllSourceTypes ? 'repository:v1' : 'repository-production-replay:v1',
            );
        }
        if (($this->environment)() === 'production') {
            throw new BenchmarkCommandException('acceptance_forbidden_in_production');
        }
        if (($this->env)('RUN_ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK') !== '1') {
            throw new BenchmarkCommandException('acceptance_gate_disabled');
        }
        if ($this->acceptanceOrganizationId === null || $this->acceptanceOrganizationId < 1
            || $this->acceptanceLoader === null || $this->acceptanceManifestLocator === null
            || ! preg_match('#^s3://org-[1-9][0-9]*/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+$#', $this->acceptanceManifestLocator)
            || str_contains($this->acceptanceManifestLocator, '?')) {
            throw new BenchmarkCommandException('acceptance_private_corpus_not_configured');
        }

        return $this->acceptanceLoader->load(
            $this->acceptanceOrganizationId,
            $this->acceptanceManifestLocator,
            BenchmarkManifest::fromFile($this->repositoryManifestPath, $this->fixtureRoot),
        );
    }

    private function failureRate(): float
    {
        $value = filter_var($this->option('max-failure-rate'), FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw new BenchmarkCommandException('failure_rate_invalid');
        }
        $rate = (float) $value;
        $policy = (string) $this->option('failure-policy-version');
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,95}$/', $policy)) {
            throw new BenchmarkCommandException('failure_policy_version_invalid');
        }
        if ($rate > 0.0 && $policy === 'strict-zero:v1') {
            throw new BenchmarkCommandException('failure_threshold_policy_mismatch');
        }

        return $rate;
    }

    private function writeOutput(string $relative, string $contents): void
    {
        $normalized = str_replace('\\', '/', $relative);
        if (str_starts_with($normalized, 'storage/app/benchmarks/')) {
            $normalized = substr($normalized, strlen('storage/app/benchmarks/'));
        }
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)
            || str_contains($normalized, '../') || ! preg_match('#^[a-zA-Z0-9._/-]+\.(json|txt)$#', $normalized)) {
            throw new BenchmarkCommandException('output_path_invalid');
        }
        if (! file_exists($this->outputRoot) && ! mkdir($this->outputRoot, 0750, true) && ! is_dir($this->outputRoot)) {
            throw new BenchmarkCommandException('output_root_unavailable');
        }
        $root = realpath($this->outputRoot);
        if ($root === false || ! is_dir($root) || is_link($this->outputRoot)) {
            throw new BenchmarkCommandException('output_root_invalid');
        }
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $parent = dirname($path);
        if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
            throw new BenchmarkCommandException('output_directory_unavailable');
        }
        $realParent = realpath($parent);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        if ($realParent === false || ! str_starts_with(str_replace('\\', '/', $realParent).'/', $prefix) || file_exists($path)) {
            throw new BenchmarkCommandException('output_path_unsafe');
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new BenchmarkCommandException('output_create_failed');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new BenchmarkCommandException('output_write_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function tablePayload(BenchmarkReportData $report): string
    {
        $lines = [
            'dataset | cases | attempted | succeeded | failed | skipped',
            implode(' | ', [$report->dataset->value, $report->caseCount, $report->attemptedCount, $report->succeededCount, $report->failedCount, $report->skippedCount]),
            'metric | macro | micro',
        ];
        foreach ($report->metrics as $name => $metric) {
            $lines[] = implode(' | ', [$name, number_format((float) $metric['macro'], 6, '.', ''), number_format((float) $metric['micro'], 6, '.', '')]);
        }

        return implode(PHP_EOL, $lines);
    }

    private function safeCode(Throwable $exception): string
    {
        return $exception instanceof BenchmarkCommandException || $exception instanceof BenchmarkContractException
            ? $exception->reason
            : 'benchmark_failed';
    }
}
