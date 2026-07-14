<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCommandException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\DatasetPrivateBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionImmutableBenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RegisteredBenchmarkManifestRepository;
use Illuminate\Console\Command;
use Throwable;

final class RunEstimateGenerationBenchmarkCommand extends Command
{
    protected $signature = 'estimate-generation:benchmark
        {--dataset=regression : development|regression|acceptance}
        {--format=json : json|table}
        {--output= : Relative path under the benchmark output root}
        {--emit-json : Emit JSON even when an immutable output is written}
        {--manifest= : Relative two-case replay manifest under the fixture root}
        {--manifest-sha256= : Exact immutable manifest content hash}
        {--base-prefix= : Exact tenant import object prefix for development/regression}
        {--organization-id= : Tenant owning the private manifest}
        {--adapter= : Explicit registered adapter}
        {--pipeline-version= : Version of the evaluated pipeline}
        {--prompt-version=none:v1 : Version of the evaluated prompt set}
        {--settings-snapshot-id= : Immutable settings snapshot identity}
        {--settings-snapshot-version= : Immutable settings snapshot version}
        {--settings-scope= : global|organization}
        {--settings-organization-id= : Organization identity for an override}
        {--settings-snapshot-hash= : Canonical settings snapshot hash}
        {--settings-limits= : Canonical JSON processing limits}
        {--normative-version= : Normative version under evaluation}
        {--price-version= : Price version under evaluation}
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
        private readonly ?BenchmarkReportOutputStore $reportOutput = null,
        private readonly ?AcceptanceBenchmarkGate $acceptanceGate = null,
        private readonly ?DatasetPrivateBenchmarkCorpusLoader $datasetPrivateLoader = null,
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
            $this->assertProductionPolicy($dataset);
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
            if ($dataset === BenchmarkDatasetType::Acceptance) {
                ($this->acceptanceGate ?? throw new BenchmarkCommandException('acceptance_master_gate_not_configured'))->assert($report);
            }
            $payload = $report->jsonSerialize();
            if (($settingsLimits = (string) $this->option('settings-limits')) !== '') {
                $payload['settings_snapshot_id'] = (int) $this->option('settings-snapshot-id');
                $payload['settings_snapshot_version'] = (int) $this->option('settings-snapshot-version');
                $payload['settings_scope'] = (string) $this->option('settings-scope');
                $payload['settings_organization_id'] = ($settingsOrganizationId = filter_var($this->option('settings-organization-id'), FILTER_VALIDATE_INT)) === false ? null : $settingsOrganizationId;
                $payload['settings_snapshot_hash'] = (string) $this->option('settings-snapshot-hash');
                $payload['settings_limits'] = json_decode($settingsLimits, true, 8, JSON_THROW_ON_ERROR);
            }
            $payload['normative_version'] = (string) $this->option('normative-version');
            $payload['price_version'] = (string) $this->option('price-version');
            BenchmarkReportData::sortRecursive($payload);
            $rendered = $format === 'json'
                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $this->tablePayload($report);
            $output = $this->option('output');
            if (is_string($output) && $output !== '') {
                $output = str_replace('{sha256}', hash('sha256', $rendered), $output);
                ($this->reportOutput ?? new LocalBenchmarkReportOutputStore($this->outputRoot))->write($output, $rendered);
            }
            if (! is_string($output) || $output === '' || (bool) $this->option('emit-json')) {
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
        $selectedManifest = $this->option('manifest');
        $selectedOrganizationId = filter_var($this->option('organization-id'), FILTER_VALIDATE_INT);
        $selectedSha256 = $this->option('manifest-sha256');
        if (is_string($selectedManifest) && str_starts_with($selectedManifest, 's3://')) {
            if (! is_int($selectedOrganizationId) || $selectedOrganizationId < 1
                || ! is_string($selectedSha256) || preg_match('/^[a-f0-9]{64}$/', $selectedSha256) !== 1
                || $this->acceptanceLoader === null) {
                throw new BenchmarkCommandException('private_manifest_identity_invalid');
            }

            if ($dataset === BenchmarkDatasetType::Acceptance) {
                return $this->acceptanceLoader->loadForDataset($dataset, $selectedOrganizationId, $selectedManifest, $selectedSha256);
            }
            $basePrefix = $this->option('base-prefix');
            if (! is_string($basePrefix) || $this->datasetPrivateLoader === null) {
                throw new BenchmarkCommandException('dataset_private_base_prefix_invalid');
            }

            return $this->datasetPrivateLoader->load($dataset, $selectedOrganizationId, $basePrefix, $selectedManifest, $selectedSha256);
        }
        if ($dataset !== BenchmarkDatasetType::Acceptance) {
            $manifestPath = $this->repositoryManifestPath;
            $requireAllSourceTypes = true;
            $manifest = $selectedManifest;
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
        if (($this->environment)() !== 'production' && ($this->env)('RUN_ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK') !== '1') {
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
            ($this->environment)() === 'production'
                ? null
                : BenchmarkManifest::fromFile($this->repositoryManifestPath, $this->fixtureRoot),
        );
    }

    private function assertProductionPolicy(BenchmarkDatasetType $dataset): void
    {
        if (($this->environment)() !== 'production') {
            return;
        }
        $manifest = $this->option('manifest');
        if ($dataset !== BenchmarkDatasetType::Acceptance
            && (! is_string($manifest) || ! str_starts_with($manifest, 's3://'))) {
            throw new BenchmarkCommandException('repository_benchmark_forbidden_in_production');
        }
        if (! $this->reportOutput instanceof ProductionImmutableBenchmarkReportOutputStore) {
            throw new BenchmarkCommandException('production_output_store_invalid');
        }
        $output = $this->option('output');
        if (! is_string($output) || ! preg_match('#^s3://org-[1-9][0-9]*/estimate-generation/benchmarks/[0-9a-f-]{36}/(?:[a-f0-9]{64}|\{sha256\})\.json$#', $output)) {
            throw new BenchmarkCommandException('production_output_locator_invalid');
        }
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
