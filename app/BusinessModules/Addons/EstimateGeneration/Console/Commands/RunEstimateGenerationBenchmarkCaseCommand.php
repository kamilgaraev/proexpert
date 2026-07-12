<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\CurrentBaselineBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use Illuminate\Console\Command;
use Throwable;

final class RunEstimateGenerationBenchmarkCaseCommand extends Command
{
    protected $signature = 'estimate-generation:benchmark-case
        {--manifest-ref= : Registered manifest reference}
        {--case-id= : Stable benchmark case ID}
        {--adapter= : Registered benchmark adapter}';

    protected $description = 'Внутренний изолированный worker одного benchmark-кейса AI-сметчика.';

    public function __construct(
        private readonly BenchmarkAdapterRegistry $adapters,
        private readonly string $repositoryManifestPath,
        private readonly string $fixtureRoot,
        private readonly AcceptanceBenchmarkCorpusLoader $acceptanceLoader,
        private readonly PdfTextLayerExtractor $pdfText,
        private readonly DrawingGeometryAnalyzer $drawing,
        private readonly ?int $acceptanceOrganizationId = null,
        private readonly ?string $acceptanceManifestLocator = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $corpus = $this->corpus((string) $this->option('manifest-ref'));
            $case = $corpus->manifest->case((string) $this->option('case-id'));
            $adapterId = (string) $this->option('adapter');
            $adapter = $adapterId === 'current-baseline'
                ? new CurrentBaselineBenchmarkAdapter($corpus->objects, $this->pdfText, $this->drawing)
                : $this->adapters->get($adapterId);
            $this->line($adapter->run(
                \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData::fromCase($case),
                3_600_000,
            )->protocolJson());

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData::technicalFailure(
                'worker_request_invalid',
            )->protocolJson());

            return self::SUCCESS;
        }
    }

    private function corpus(string $reference): BenchmarkCorpus
    {
        if ($reference === 'repository:v1') {
            return new BenchmarkCorpus(
                BenchmarkManifest::fromFile($this->repositoryManifestPath, $this->fixtureRoot),
                new LocalBenchmarkObjectReader($this->fixtureRoot),
                $reference,
            );
        }
        if ($this->acceptanceOrganizationId === null || $this->acceptanceManifestLocator === null) {
            throw new \InvalidArgumentException('acceptance_worker_not_configured');
        }
        if (app()->environment('production') || getenv('RUN_ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK') !== '1') {
            throw new \InvalidArgumentException('acceptance_worker_forbidden');
        }
        $corpus = $this->acceptanceLoader->load(
            $this->acceptanceOrganizationId,
            $this->acceptanceManifestLocator,
            BenchmarkManifest::fromFile($this->repositoryManifestPath, $this->fixtureRoot),
        );
        if (! hash_equals($corpus->executionReference, $reference)) {
            throw new \InvalidArgumentException('manifest_reference_invalid');
        }

        return $corpus;
    }
}
