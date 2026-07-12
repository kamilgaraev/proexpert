<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationUnitJobDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EvidenceSourceReplacementInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\LaravelDocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\LaravelEstimateGenerationUnitJobDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\MetadataDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\EloquentGeometryRegenerationIntentStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntentStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\NoopGeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EloquentRetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\LaravelEstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\CurrentBaselineBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceAcceptanceBenchmarkObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\PrivateBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionReplayBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedBenchmarkCatalogLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RegisteredBenchmarkManifestRepository;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedReplayProjectionLoader;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\BootstrapEstimateGenerationLearningCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\InspectEstimateGenerationProductionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEstimateGenerationBenchmarkCaseCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEstimateGenerationBenchmarkCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EloquentSessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceDocumentSourceReplacementInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\DeliverEstimateGenerationFinalizationsJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationTrainingDatasetJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverEstimateGenerationPipelinesJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverEstimateGenerationUnitsJob;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\BackfillNormativeRetrievalCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\ClassifyEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\ImportEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\InspectEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\QualityEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\RollbackRegionalPricePeriodCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\SyncFgiscsBuildingResourcePricesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\SyncFgiscsRegionalPricesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\RolloutNormativeRetrievalCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ApprovedNormativeDatasetLookup;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EloquentApprovedNormativeDatasetLookup;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsClient;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalCatalogService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceActivationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceQualityService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateImportStatisticsService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateNormativeQualityService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateResourceClassificationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateResourceClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateSourceImportService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FgiscsBuildingResourcePriceSpreadsheetParser;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NoopNormativeRolloutFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRolloutFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PostgresNormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\SystemNormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentFailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentFailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentFinalizationDeliveryStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentFinalizationOutbox;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineExecutionPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\FinalizationDeliveryStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\FinalizationOutbox;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCompletionHook;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineExecutionPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishValidatedDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\S3PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\AssembleResourcesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\BuildDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ExtractQuantitiesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ResolvePricesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandDocumentsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandObjectStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\ConstructionDocumentClassifierService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentUnderstandingSummaryBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationAuditService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSearchService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\TimewebVisionOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\ConstructionDocumentFactExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentFactMerger;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrPreflightService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrQualityAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionResponseBodyReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryResourceLimits;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Providers\BoundedVisionResponseBodyReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Providers\TimewebVisionProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class EstimateGenerationServiceProvider extends ServiceProvider
{
    public const MINIMUM_PIPELINE_LEASE_SECONDS = 2100;

    public function register(): void
    {
        $this->app->singleton(SessionOperationalSnapshotBuilder::class, BuildSessionOperationalSnapshot::class);
        $this->mergeConfigFrom(config_path('estimate-generation.php'), 'estimate-generation');
        $this->app->singleton(MetricRegistry::class, static fn (): MetricRegistry => MetricRegistry::standard());
        $this->app->singleton(BenchmarkCaseExecutor::class, static fn (): BenchmarkCaseExecutor => new ProcessBenchmarkCaseExecutor(
            PHP_BINARY,
            base_path('artisan'),
        ));
        $this->app->singleton(BenchmarkObjectReader::class, LocalBenchmarkObjectReader::class);
        $this->app->singleton(BenchmarkPrivateObjectStore::class, FileServiceBenchmarkPrivateObjectStore::class);
        $this->app->when([AcceptanceBenchmarkCorpusLoader::class, PrivateBenchmarkObjectReader::class])
            ->needs(BenchmarkPrivateObjectStore::class)
            ->give(FileServiceAcceptanceBenchmarkObjectStore::class);
        $this->app->singleton(AcceptanceBenchmarkCorpusLoader::class);
        $this->app->singleton(BenchmarkRunner::class);
        $this->app->singleton(RegisteredBenchmarkManifestRepository::class, static fn (): RegisteredBenchmarkManifestRepository => new RegisteredBenchmarkManifestRepository(
            base_path('tests/Fixtures/EstimateGeneration/benchmarks'),
            (array) config('estimate-generation.benchmark.registered_manifests', []),
        ));
        $this->app->singleton(RasterPreprocessor::class);
        $this->app->singleton(GeometryResourceLimits::class, static fn (): GeometryResourceLimits => new GeometryResourceLimits(
            memoryLimitKiB: (int) config('estimate-generation.vision.geometry_runtime.memory_limit_kib'),
            cpuLimitSeconds: (int) config('estimate-generation.vision.geometry_runtime.cpu_limit_seconds'),
            fileSizeLimitBytes: (int) config('estimate-generation.vision.geometry_runtime.file_size_limit_bytes'),
            openFileLimit: (int) config('estimate-generation.vision.geometry_runtime.open_file_limit'),
        ));
        $this->app->singleton(VisionResponseBodyReader::class, BoundedVisionResponseBodyReader::class);
        $this->app->singleton(TimewebVisionProvider::class);
        $this->app->singleton(VisionProvider::class, TimewebVisionProvider::class);
        $this->app->singleton(CadGeometryProvider::class, DwgDxfGeometryProvider::class);
        $this->app->singleton(BenchmarkAdapterRegistry::class, static fn ($app): BenchmarkAdapterRegistry => new BenchmarkAdapterRegistry([
            $app->make(CurrentBaselineBenchmarkAdapter::class),
            new ProductionReplayBenchmarkAdapter(
                new RecordedReplayProjectionLoader(base_path('tests/Fixtures/EstimateGeneration/benchmarks')),
                new RecordedPortEnvelopeLoader(
                    base_path('tests/Fixtures/EstimateGeneration/benchmarks'),
                    base_path('tests/Fixtures/EstimateGeneration/benchmarks/recordings/manifest.json'),
                ),
                new RecordedBenchmarkCatalogLoader(base_path('tests/Fixtures/EstimateGeneration/benchmarks')),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler::class),
                $app->make(ResourceAssemblyService::class),
                $app->make(NormativeWorkIntentFactory::class),
                $app->make(EstimateValidationService::class),
                (array) config('estimate-generation.benchmark.production_replay_projections', []),
            ),
        ]));
        $this->app->singleton(RunEstimateGenerationBenchmarkCaseCommand::class, fn ($app): RunEstimateGenerationBenchmarkCaseCommand => new RunEstimateGenerationBenchmarkCaseCommand(
            $app->make(BenchmarkAdapterRegistry::class),
            base_path('tests/Fixtures/EstimateGeneration/benchmarks/manifest.json'),
            base_path('tests/Fixtures/EstimateGeneration/benchmarks'),
            $app->make(AcceptanceBenchmarkCorpusLoader::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor::class),
            $app->make(DrawingGeometryAnalyzer::class),
            (($organizationId = (int) config('estimate-generation.benchmark.acceptance_organization_id', 0)) > 0)
                ? $organizationId
                : null,
            (static function (): ?string {
                $locator = config('estimate-generation.benchmark.acceptance_manifest');

                return is_string($locator) ? $locator : null;
            })(),
            $app->make(RegisteredBenchmarkManifestRepository::class),
        ));
        $this->app->singleton(RunEstimateGenerationBenchmarkCommand::class, fn ($app): RunEstimateGenerationBenchmarkCommand => new RunEstimateGenerationBenchmarkCommand(
            $app->make(BenchmarkRunner::class),
            $app->make(BenchmarkAdapterRegistry::class),
            base_path('tests/Fixtures/EstimateGeneration/benchmarks/manifest.json'),
            base_path('tests/Fixtures/EstimateGeneration/benchmarks'),
            storage_path('app/benchmarks'),
            acceptanceManifestLocator: (static function (): ?string {
                $locator = config('estimate-generation.benchmark.acceptance_manifest');

                return is_string($locator) ? $locator : null;
            })(),
            acceptanceOrganizationId: (($organizationId = (int) config('estimate-generation.benchmark.acceptance_organization_id', 0)) > 0)
                ? $organizationId
                : null,
            acceptanceLoader: $app->make(AcceptanceBenchmarkCorpusLoader::class),
            registeredManifests: $app->make(RegisteredBenchmarkManifestRepository::class),
        ));

        $this->app->singleton(DocumentParsingService::class);
        $this->app->singleton(MetadataDocumentUnitDetector::class);
        $this->app->singleton(DocumentSourceManifestStorage::class, S3DocumentSourceManifestStorage::class);
        $this->app->singleton(DocumentUnitDetector::class, ArtifactDocumentUnitDetector::class);
        $this->app->singleton(DocumentProcessingUnitStore::class, EloquentDocumentProcessingUnitStore::class);
        $this->app->singleton(DocumentUnitDispatchStore::class, EloquentDocumentUnitDispatchStore::class);
        $this->app->singleton(EstimateGenerationUnitJobDispatcher::class, LaravelEstimateGenerationUnitJobDispatcher::class);
        $this->app->singleton(DocumentUnitExhaustionHandler::class, EloquentDocumentUnitExhaustionHandler::class);
        $this->app->singleton(DocumentUnitContentReader::class, S3DocumentUnitContentReader::class);
        $this->app->singleton(DocumentUnitProcessor::class, OcrDocumentUnitProcessor::class);
        $this->app->singleton(DocumentUnitAggregateReconciler::class, EloquentDocumentUnitAggregateReconciler::class);
        $this->app->singleton(DocumentSourceReplacementTransaction::class, LaravelDocumentSourceReplacementTransaction::class);
        $this->app->singleton(EvidenceRepository::class, EloquentEvidenceRepository::class);
        $this->app->singleton(EvidenceSourceReplacementInvalidator::class, EvidenceDocumentSourceReplacementInvalidator::class);
        $this->app->singleton(SessionStateStore::class, EloquentSessionStateStore::class);
        $this->app->singleton(OcrClientInterface::class, TimewebVisionOcrClient::class);
        $this->app->singleton(AiUsageStore::class, EloquentAiUsageStore::class);
        $this->app->singleton(FailureStore::class, EloquentFailureStore::class);
        $this->app->singleton(FailureWorkflowHandler::class, EloquentFailureWorkflowHandler::class);
        $this->app->singleton(PipelineCompletionHook::class, PublishValidatedDraft::class);
        $this->app->singleton(FinalizationOutbox::class, fn ($app) => new EloquentFinalizationOutbox($app->make('db')->connection()));
        $this->app->singleton(FinalizationDeliveryStore::class, fn ($app) => new EloquentFinalizationDeliveryStore($app->make('db')->connection()));
        $this->app->singleton(PipelineDefinitionGraph::class, static fn (): PipelineDefinitionGraph => PipelineDefinitionGraph::standard());
        $this->app->singleton(PipelineArtifactStore::class, S3PipelineArtifactStore::class);
        $this->app->singleton(PipelineCheckpointStore::class, fn ($app) => new EloquentPipelineCheckpointStore(
            $app->make('db')->connection(),
            $app->make(PipelineCompletionHook::class),
        ));
        $this->app->singleton(PipelineOutputRepository::class, EloquentPipelineOutputRepository::class);
        $this->app->singleton(PipelineExecutionPlanner::class, EloquentPipelineExecutionPlanner::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RecoverEstimateGenerationPipelines::class,
            fn ($app) => new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RecoverEstimateGenerationPipelines(
                $app->make('db')->connection(),
            ),
        );
        $this->app->singleton(GenerationPipelineDataGateway::class, EloquentGenerationPipelineDataGateway::class);
        $this->app->singleton(PipelineRegistry::class, fn ($app) => new PipelineRegistry([
            $app->make(UnderstandDocumentsStage::class),
            $app->make(UnderstandObjectStage::class),
            $app->make(ExtractQuantitiesStage::class),
            $app->make(PlanWorkItemsStage::class),
            $app->make(MatchNormativesStage::class),
            $app->make(AssembleResourcesStage::class),
            $app->make(ResolvePricesStage::class),
            $app->make(BuildDraftStage::class),
            $app->make(ValidateDraftStage::class),
        ]));
        $this->app->singleton(PipelineRunner::class, fn ($app) => new PipelineRunner(
            registry: $app->make(PipelineRegistry::class),
            checkpointStore: $app->make(PipelineCheckpointStore::class),
            failureRecorder: $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder::class),
            failureWorkflowHandler: $app->make(FailureWorkflowHandler::class),
            clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable,
            leaseSeconds: max(self::MINIMUM_PIPELINE_LEASE_SECONDS, (int) config(
                'estimate-generation.generation.pipeline_lease_seconds',
                self::MINIMUM_PIPELINE_LEASE_SECONDS,
            )),
        ));
        $this->app->singleton(RerankWireClient::class, TimewebRerankWireClient::class);
        $this->app->singleton(OcrDocumentStorageService::class);
        $this->app->singleton(OcrPreflightService::class);
        $this->app->singleton(SpreadsheetDocumentExtractor::class);
        $this->app->singleton(DocumentGenerationReadinessService::class);
        $this->app->singleton(OcrQualityAnalyzer::class);
        $this->app->singleton(ConstructionDocumentFactExtractor::class);
        $this->app->singleton(DocumentFactMerger::class);
        $this->app->singleton(ConstructionDocumentClassifierService::class);
        $this->app->singleton(DocumentUnderstandingSummaryBuilder::class);
        $this->app->singleton(EstimatorScopeInferenceService::class);
        $this->app->singleton(ConstructionSemanticParser::class);
        $this->app->singleton(EstimateDecompositionService::class);
        $this->app->singleton(ProjectDocumentNormativeReferenceExtractor::class);
        $this->app->singleton(EstimatorReadinessService::class);
        $this->app->singleton(NormativeWorkItemPlannerService::class);
        $this->app->singleton(ResourceAssemblyService::class);
        $this->app->singleton(EstimatePricingService::class);
        $this->app->singleton(EstimateValidationService::class);
        $this->app->singleton(EstimateDraftPersistenceService::class);
        $this->app->singleton(GeneratedEstimateNumberAllocator::class, LaravelGeneratedEstimateNumberAllocator::class);
        $this->app->singleton(RetryableEstimateGenerationSessionRepository::class, EloquentRetryableEstimateGenerationSessionRepository::class);
        $this->app->singleton(EstimateGenerationRetryDispatcher::class, LaravelEstimateGenerationRetryDispatcher::class);
        $this->app->singleton(GeometryRegenerationIntentStore::class, EloquentGeometryRegenerationIntentStore::class);
        $this->app->singleton(GeometryConfirmationFaultInjector::class, NoopGeometryConfirmationFaultInjector::class);
        $this->app->singleton(GeneratedEstimateWriter::class, LaravelGeneratedEstimateWriter::class);
        $this->app->singleton(EstimateGenerationAuditService::class);
        $this->app->singleton(NormativeScopeRuleCatalog::class);
        $this->app->singleton(WorkIntentClassifier::class);
        $this->app->singleton(NormativeCandidateSearchService::class);
        $this->app->singleton(NormativeCandidateSource::class, PostgresNormativeCandidateSource::class);
        $this->app->singleton(NormativeHardGate::class);
        $this->app->singleton(ApprovedNormativeDatasetLookup::class, EloquentApprovedNormativeDatasetLookup::class);
        $this->app->singleton(NormativePinClock::class, SystemNormativePinClock::class);
        $this->app->singleton(NormativeRolloutFaultInjector::class, NoopNormativeRolloutFaultInjector::class);
        $this->app->singleton(NormativeRerankerModelSet::class);
        $this->app->singleton(NormativeMatchingWorkflow::class);
        $this->app->singleton(NormativeWorkIntentFactory::class);
        $this->app->singleton(NormativeRetrievalService::class, fn ($app) => new NormativeRetrievalService(
            $app->make(NormativeCandidateSource::class),
            $app->make(NormativeHardGate::class),
            max(1, min(32, (int) config('estimate-generation.normative_matching.retrieval.max_candidates', 16))),
            is_string(config('estimate-generation.normative_matching.retrieval.semantic_index_version'))
                ? config('estimate-generation.normative_matching.retrieval.semantic_index_version')
                : null,
        ));
        $this->app->singleton(EstimateGenerationLearningEvidenceService::class);
        $this->app->singleton(LLMNormativeCandidateReranker::class, fn ($app) => new LLMNormativeCandidateReranker(
            $app->make(LLMProviderInterface::class),
            $app->make(AttemptAwareNormativeLlmClient::class),
        ));
        $this->app->singleton(NormativeCandidateRerankerInterface::class, LLMNormativeCandidateReranker::class);
        $this->app->singleton(EstimateSourceStorageService::class);
        $this->app->singleton(EstimateSourceImportService::class);
        $this->app->singleton(EstimateImportStatisticsService::class);
        $this->app->singleton(EstimateNormativeQualityService::class);
        $this->app->singleton(EstimateResourceClassifier::class);
        $this->app->singleton(EstimateResourceClassificationService::class);
        $this->app->singleton(EstimateNormativeMatcher::class);
        $this->app->singleton(FgiscsClient::class);
        $this->app->singleton(FgiscsRegionalCatalogService::class);
        $this->app->singleton(FgiscsRegionalPriceUpdateService::class);
        $this->app->singleton(FgiscsBuildingResourcePriceSpreadsheetParser::class);
        $this->app->singleton(FgiscsBuildingResourcePriceUpdateService::class);
        $this->app->singleton(RegionalPriceQualityService::class);
        $this->app->singleton(RegionalPriceActivationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->registerQueueRateLimiters();

        $routesPath = __DIR__.'/routes.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        }

        if ($this->app->runningInConsole()) {
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->job(new RecoverEstimateGenerationUnitsJob)
                    ->everyMinute()
                    ->withoutOverlapping();
                $this->app->make(Schedule::class)
                    ->job(new RecoverEstimateGenerationPipelinesJob)
                    ->everyMinute()
                    ->withoutOverlapping();
                $this->app->make(Schedule::class)
                    ->job(new DeliverEstimateGenerationFinalizationsJob)
                    ->everyMinute()
                    ->withoutOverlapping();
            });
            $this->commands([
                BootstrapEstimateGenerationLearningCommand::class,
                InspectEstimateGenerationProductionCommand::class,
                RunEstimateGenerationBenchmarkCommand::class,
                RunEstimateGenerationBenchmarkCaseCommand::class,
                ClassifyEstimateNormativesCommand::class,
                ImportEstimateNormativesCommand::class,
                InspectEstimateNormativesCommand::class,
                QualityEstimateNormativesCommand::class,
                SyncFgiscsRegionalPricesCommand::class,
                SyncFgiscsBuildingResourcePricesCommand::class,
                RollbackRegionalPricePeriodCommand::class,
                BackfillNormativeRetrievalCommand::class,
                RolloutNormativeRetrievalCommand::class,
            ]);
        }
    }

    private function registerQueueRateLimiters(): void
    {
        RateLimiter::for('estimate-generation-drafts', static function (object $job): Limit {
            $key = $job instanceof GenerateEstimateDraftJob ? $job->rateLimitKey() : 'global';

            return Limit::perMinute(max(1, (int) config('estimate-generation.generation.max_draft_jobs_per_minute', 3)))
                ->by($key);
        });

        RateLimiter::for('estimate-generation-ocr-documents', static function (object $job): Limit {
            $key = $job instanceof ProcessEstimateGenerationDocumentJob ? $job->rateLimitKey() : 'global';

            return Limit::perMinute(max(1, (int) config('estimate-generation.ocr.max_document_jobs_per_minute', 6)))
                ->by($key);
        });

        RateLimiter::for('estimate-generation-document-units', static function (object $job): Limit {
            $key = $job instanceof ProcessEstimateGenerationUnitJob ? $job->rateLimitKey() : 'global';

            return Limit::perMinute(max(1, (int) config('estimate-generation.ocr.max_unit_jobs_per_minute', 30)))
                ->by($key);
        });

        RateLimiter::for('estimate-generation-training-datasets', static function (object $job): Limit {
            $key = $job instanceof ProcessEstimateGenerationTrainingDatasetJob ? $job->rateLimitKey() : 'global';

            return Limit::perMinute(max(1, (int) config('estimate-generation.training.max_dataset_jobs_per_minute', 2)))
                ->by($key);
        });
    }
}
