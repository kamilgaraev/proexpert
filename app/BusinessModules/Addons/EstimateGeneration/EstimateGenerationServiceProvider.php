<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\DocumentArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RunDocumentArbitration;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\TimewebEstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\DocumentObserverRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\RunIndependentObservers;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\TimewebProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationResourceMeter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementPageStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentSourceReplacementPageStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationUnitJobDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EvidenceSourceReplacementInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\LaravelDocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\LaravelEstimateGenerationUnitJobDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\MetadataDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProductionDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverStalledEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SystemDocumentRepresentationResourceMeter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EloquentRetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\LaravelEstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\AdvanceTargetedPackageReviewUpdater;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\CommitTargetedPackageRebuild;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\EloquentTargetedPackageCommitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\EloquentTargetedPackageRebuildOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\EloquentTargetedPackageRebuildSessionSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\RunTargetedPackageRebuildOperation;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageCommitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildCommitter;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildJobHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildJobScheduler;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationService;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildSessionSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageReviewUpdater;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\CurrentBaselineBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceAcceptanceBenchmarkObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ImmutableBenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\PrivateBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RegisteredBenchmarkManifestRepository;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\BootstrapEstimateGenerationLearningCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\InspectCadRuntimeReadinessCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\InspectEstimateGenerationProductionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEstimateGenerationBenchmarkCaseCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEstimateGenerationBenchmarkCommand;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEvaluationReleaseGateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EloquentSessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EloquentEvaluationCorpusRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpusRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReleaseGate;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceDocumentSourceReplacementInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\LaravelTargetedPackageRebuildJobScheduler;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
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
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePricePriority;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsClient;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalCatalogService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceSynchronizationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalPriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceActivationService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceImportLifecycleService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceQualityService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceVersionResolver;
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
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentFailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorderObserver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SafeLogFailureRecorderObserver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineExecutionPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPublishDraftOnce;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCompletionHook;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineExecutionPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishDraftOnce;
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
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
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
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ShadowArbiterCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\TargetedPackageRebuildReviewer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\VisionModelPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionResponseBodyReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeConfiguration;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeReadinessInspector;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryProcessRunner;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryResourceLimits;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Providers\BoundedVisionResponseBodyReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Providers\TimewebVisionProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Services\Billing\CommercialQuotaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class EstimateGenerationServiceProvider extends ServiceProvider
{
    public const MINIMUM_PIPELINE_LEASE_SECONDS = 2250;

    public function register(): void
    {
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore::class,
            \App\BusinessModules\Addons\EstimateGeneration\Settings\EloquentEffectiveSettingsOperationStore::class,
        );
        $this->app->singleton(EffectiveSettingsResolver::class, static fn ($app): EffectiveSettingsResolver => new EffectiveSettingsResolver(
            $app->make(EffectiveSettingsOperationStore::class),
            is_string(config('estimate-generation.vision.model_override'))
                ? config('estimate-generation.vision.model_override')
                : null,
            (string) config('estimate-generation.vision.model', VisionModelPolicy::LUNA),
        ));
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Settings\DocumentRuntimeLimits::class,
            \App\BusinessModules\Addons\EstimateGeneration\Settings\DocumentRuntimeLimitsGuard::class,
        );
        $this->app->singleton(AiPricingCatalog::class, static fn (): AiPricingCatalog => new AiPricingCatalog(
            is_array(config('estimate-generation.ai_pricing_catalog')) ? config('estimate-generation.ai_pricing_catalog') : [],
        ));
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class,
            \App\BusinessModules\Addons\EstimateGeneration\Observability\CatalogAiPriceSnapshotResolver::class,
        );
        $this->app->singleton(AttemptAwareNormativeLlmClient::class, static fn ($app): AttemptAwareNormativeLlmClient => new AttemptAwareNormativeLlmClient(
            $app->make(RerankWireClient::class),
            $app->make(AiUsageStore::class),
            null,
            null,
            $app->make(NormativeRerankerModelSet::class),
            $app->make(EffectiveSettingsResolver::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
        ));
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\CompletenessArbiter::class,
            static function ($app): \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\CompletenessArbiter {
                $settings = config('estimate-generation.completeness_arbiter');
                $settings = is_array($settings) ? $settings : [];
                $model = trim((string) ($settings['model'] ?? 'openai/gpt-5-mini'));
                $promptVersion = trim((string) ($settings['prompt_version'] ?? 'completeness-arbiter:v1'));
                if (($settings['enabled'] ?? false) !== true) {
                    return new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\DisabledCompletenessArbiter($model, $promptVersion);
                }

                return new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\AttemptAwareCompletenessArbiter(
                    $app->make(RerankWireClient::class),
                    $app->make(AiUsageStore::class),
                    $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
                    $model,
                    $promptVersion,
                    trim((string) ($settings['schema_version'] ?? 'completeness-arbiter:v1')),
                    max(1, min(64_000, (int) ($settings['max_input_tokens'] ?? 24_000))),
                    max(1, min(8_000, (int) ($settings['max_output_tokens'] ?? 2_000))),
                    max(1, min(120, (int) ($settings['timeout_seconds'] ?? 20))),
                );
            },
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ShadowArbiterCoordinator::class);
        $this->app->singleton(TargetedPackageRebuildOperationStore::class, EloquentTargetedPackageRebuildOperationStore::class);
        $this->app->singleton(TargetedPackageRebuildSessionSource::class, EloquentTargetedPackageRebuildSessionSource::class);
        $this->app->singleton(TargetedPackageRebuildExecutor::class, TargetedPackageRebuilder::class);
        $this->app->singleton(TargetedPackageRebuildReviewer::class, ShadowArbiterCoordinator::class);
        $this->app->singleton(TargetedPackageCommitStore::class, EloquentTargetedPackageCommitStore::class);
        $this->app->singleton(TargetedPackageDraftWriter::class, \App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService::class);
        $this->app->singleton(TargetedPackageReviewUpdater::class, AdvanceTargetedPackageReviewUpdater::class);
        $this->app->singleton(TargetedPackageRebuildCommitter::class, CommitTargetedPackageRebuild::class);
        $this->app->singleton(TargetedPackageRebuildJobScheduler::class, LaravelTargetedPackageRebuildJobScheduler::class);
        $this->app->singleton(TargetedPackageRebuildJobHandler::class, static function ($app): RunTargetedPackageRebuildOperation {
            $settings = config('estimate-generation.completeness_arbiter');
            $settings = is_array($settings) ? $settings : [];

            return new RunTargetedPackageRebuildOperation(
                $app->make(TargetedPackageRebuildOperationStore::class),
                $app->make(TargetedPackageRebuildSessionSource::class),
                $app->make(TargetedPackageRebuildExecutor::class),
                $app->make(TargetedPackageRebuildReviewer::class),
                $app->make(TargetedPackageRebuildCommitter::class),
                ($settings['active_targeted_rebuild_enabled'] ?? false) === true,
            );
        });
        $this->app->singleton(TargetedPackageRebuildOperationFactory::class);
        $this->app->singleton(TargetedPackageRebuildOperationService::class, static function ($app): TargetedPackageRebuildOperationService {
            $settings = config('estimate-generation.completeness_arbiter');
            $settings = is_array($settings) ? $settings : [];

            return new TargetedPackageRebuildOperationService(
                $app->make(TargetedPackageRebuildOperationStore::class),
                $app->make(TargetedPackageRebuildOperationFactory::class),
                $app->make(TargetedPackageRebuildJobScheduler::class),
                ($settings['active_targeted_rebuild_enabled'] ?? false) === true,
            );
        });
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Planning\WorkCompositionLlmClient::class,
            \App\BusinessModules\Addons\EstimateGeneration\Planning\AttemptAwareWorkCompositionLlmClient::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardRepository::class,
            \App\BusinessModules\Addons\EstimateGeneration\Monitoring\SqlEstimateGenerationDashboardRepository::class,
        );
        $this->app->singleton(SessionOperationalSnapshotBuilder::class, BuildSessionOperationalSnapshot::class);
        $this->mergeConfigFrom(config_path('estimate-generation.php'), 'estimate-generation');
        if ($this->app->environment('production')
            && config('estimate-generation.benchmark.production_output_store') !== 's3') {
            throw new \InvalidArgumentException('benchmark_production_output_store_invalid');
        }
        $this->app->singleton(MetricRegistry::class, static fn (): MetricRegistry => MetricRegistry::standard());
        $this->app->singleton(BenchmarkCaseExecutor::class, static fn (): BenchmarkCaseExecutor => new ProcessBenchmarkCaseExecutor(
            PHP_BINARY,
            base_path('artisan'),
        ));
        $this->app->singleton(BenchmarkObjectReader::class, LocalBenchmarkObjectReader::class);
        $this->app->singleton(BenchmarkPrivateObjectStore::class, FileServiceBenchmarkPrivateObjectStore::class);
        $this->app->singleton(BenchmarkImmutableObjectStore::class, FileServiceBenchmarkPrivateObjectStore::class);
        $this->app->singleton(BenchmarkReportOutputStore::class, fn ($app): BenchmarkReportOutputStore => $this->app->environment('production')
            ? new ImmutableBenchmarkReportOutputStore($app->make(BenchmarkImmutableObjectStore::class))
            : new LocalBenchmarkReportOutputStore(storage_path('app/benchmarks')));
        $this->app->when([AcceptanceBenchmarkCorpusLoader::class, PrivateBenchmarkObjectReader::class])
            ->needs(BenchmarkPrivateObjectStore::class)
            ->give(FileServiceAcceptanceBenchmarkObjectStore::class);
        $this->app->singleton(AcceptanceBenchmarkCorpusLoader::class);
        $this->app->singleton(BenchmarkRunner::class);
        $this->app->singleton(RegisteredBenchmarkManifestRepository::class, fn (): RegisteredBenchmarkManifestRepository => new RegisteredBenchmarkManifestRepository(
            base_path('tests/Fixtures/EstimateGeneration/benchmarks'),
            [],
        ));
        $this->app->singleton(RasterPreprocessor::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor => new \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor(
                maxRegions: (int) config('estimate-generation.vision.adaptive_analysis.max_regions_per_page'),
                maxAggregatePixels: (int) config('estimate-generation.vision.adaptive_analysis.max_region_pixels_per_page'),
                maxSourcePixels: (int) config('estimate-generation.vision.preprocessing.max_pixels'),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionCropper::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionCropper => new \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionCropper(
                maxRegions: (int) config('estimate-generation.vision.adaptive_analysis.max_regions_per_page'),
                maxAggregateBytes: (int) config('estimate-generation.vision.adaptive_analysis.max_region_bytes_per_page'),
                maxLongEdge: (int) config('estimate-generation.vision.adaptive_analysis.max_region_long_edge'),
            ),
        );
        $this->app->singleton(GeometryResourceLimits::class, static fn (): GeometryResourceLimits => new GeometryResourceLimits(
            memoryLimitKiB: (int) config('estimate-generation.vision.geometry_runtime.memory_limit_kib'),
            cpuLimitSeconds: (int) config('estimate-generation.vision.geometry_runtime.cpu_limit_seconds'),
            fileSizeLimitBytes: (int) config('estimate-generation.vision.geometry_runtime.file_size_limit_bytes'),
            openFileLimit: (int) config('estimate-generation.vision.geometry_runtime.open_file_limit'),
        ));
        $this->app->singleton(VisionResponseBodyReader::class, BoundedVisionResponseBodyReader::class);
        $this->app->singleton(TimewebVisionProvider::class, static fn ($app): TimewebVisionProvider => new TimewebVisionProvider(
            $app->make(AiUsageStore::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionResponseBodyReader::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore::class),
            $app->make(EffectiveSettingsResolver::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Settings\DocumentRuntimeLimits::class),
        ));
        $this->app->singleton(VisionProvider::class, TimewebVisionProvider::class);
        $this->app->singleton(CadRuntimeConfiguration::class, fn (): CadRuntimeConfiguration => CadRuntimeConfiguration::fromArray(
            (array) config('estimate-generation.vision.cad_runtime', []),
            $this->app->environment('production'),
        ));
        $this->app->singleton(CadRuntimeReadinessInspector::class);
        $this->app->singleton(CadConversionRuntime::class, static function ($app): CadConversionRuntime {
            $cad = $app->make(CadRuntimeConfiguration::class);
            $limits = new GeometryResourceLimits($cad->memoryLimitKiB, $cad->cpuLimitSeconds, $cad->fileSizeLimitBytes, $cad->openFileLimit);

            return new CadConversionRuntime(
                $cad->pythonBinary, $cad->scriptPath, $cad->dwgreadBinary, $cad->timeoutSeconds,
                $cad->maxInputBytes, $cad->maxOutputBytes, $limits,
                new GeometryProcessRunner(sandboxBinary: $cad->sandboxBinary), $cad->maxEntities,
                $app->make(CadRuntimeReadinessInspector::class), $cad,
            );
        });
        $this->app->singleton(DwgDxfGeometryProvider::class, static fn ($app): DwgDxfGeometryProvider => new DwgDxfGeometryProvider(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader::class),
            $app->make(CadConversionRuntime::class),
            $app->make(CadRuntimeConfiguration::class)->maxInputBytes,
        ));
        $this->app->singleton(CadGeometryProvider::class, static fn ($app): DwgDxfGeometryProvider => $app->make(DwgDxfGeometryProvider::class));
        $this->app->singleton(BenchmarkAdapterRegistry::class, function ($app): BenchmarkAdapterRegistry {
            return new BenchmarkAdapterRegistry([$app->make(CurrentBaselineBenchmarkAdapter::class)]);
        });
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
            reportOutput: $app->make(BenchmarkReportOutputStore::class),
            acceptanceGate: $app->make(AcceptanceBenchmarkGate::class),
            datasetPrivateLoader: $app->make(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\DatasetPrivateBenchmarkCorpusLoader::class),
        ));
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Operations\StoredBenchmarkRunExecutor::class,
            \App\BusinessModules\Addons\EstimateGeneration\Operations\ConsoleStoredBenchmarkRunExecutor::class,
        );

        $this->app->singleton(DocumentParsingService::class);
        $this->app->singleton(MetadataDocumentUnitDetector::class);
        $this->app->singleton(DocumentSourceManifestStorage::class, S3DocumentSourceManifestStorage::class);
        $this->app->singleton(DocumentUnitDetector::class, ArtifactDocumentUnitDetector::class);
        $this->app->singleton(DocumentRepresentationResourceMeter::class, SystemDocumentRepresentationResourceMeter::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter::class,
            static fn ($app) => new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter(
                $app->make('db')->connection(),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class),
            ),
        );
        $this->app->singleton(DocumentProcessingUnitStore::class, static fn ($app): DocumentProcessingUnitStore => new EloquentDocumentProcessingUnitStore(
            $app->make('db')->connection(),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter::class),
        ));
        $this->app->singleton(DocumentUnitDispatchStore::class, EloquentDocumentUnitDispatchStore::class);
        $this->app->singleton(EstimateGenerationUnitJobDispatcher::class, LaravelEstimateGenerationUnitJobDispatcher::class);
        $this->app->singleton(DocumentUnitExhaustionHandler::class, EloquentDocumentUnitExhaustionHandler::class);
        $this->app->singleton(DocumentUnitContentReader::class, S3DocumentUnitContentReader::class);
        $this->app->singleton(DocumentUnitProcessor::class, ProductionDocumentUnitProcessor::class);
        $this->app->singleton(DocumentUnitAggregateReconciler::class, EloquentDocumentUnitAggregateReconciler::class);
        $this->app->singleton(DocumentSourceReplacementTransaction::class, LaravelDocumentSourceReplacementTransaction::class);
        $this->app->singleton(DocumentSourceReplacementPageStore::class, EloquentDocumentSourceReplacementPageStore::class);
        $this->app->singleton(EvidenceRepository::class, EloquentEvidenceRepository::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class,
            \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory::class,
            \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\AttemptAwareCrossDocumentFactArbitratorFactory::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget => new \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget(
                maxFacts: (int) config('estimate-generation.project_understanding.max_facts'),
                maxGroups: (int) config('estimate-generation.project_understanding.max_groups'),
                maxCandidatesTotal: (int) config('estimate-generation.project_understanding.max_candidates_total'),
                maxCandidatesPerGroup: (int) config('estimate-generation.project_understanding.max_candidates_per_group'),
                maxLinks: (int) config('estimate-generation.project_understanding.max_links'),
                maxProviderCalls: (int) config('estimate-generation.project_understanding.max_provider_calls'),
                maxEvidenceItems: (int) config('estimate-generation.project_understanding.max_evidence_items'),
                maxEvidencePayloadBytes: (int) config('estimate-generation.project_understanding.max_evidence_payload_bytes'),
            ),
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog => \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog::fromArray(
                config('estimate-generation-technology-systems'),
            ),
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationDecisionService::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessExclusionDecisionService::class);
        $this->app->bind(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger::class,
            \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\SynchronousPlanningReanalysisTrigger::class,
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource => new \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource(
                $app->make('db'),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification::class),
                (int) config('estimate-generation.project_planning.max_facts') + 1,
            ),
        );
        $this->app->alias(
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationSource::class,
        );
        $this->app->alias(
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationAnswerRegistry::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationAnswerRegistry => new \App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectModelEstimateClarificationAnswerRegistry(
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
                (int) config('estimate-generation.project_planning.max_facts') + 1,
            ),
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator => new \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator(
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog::class),
                maxFacts: (int) config('estimate-generation.project_planning.max_facts'),
                maxRecommendations: (int) config('estimate-generation.project_planning.max_recommendations'),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog => \App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog::fromArray(
                config('estimate-generation-completeness-rules'),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder::class,
            static fn (): \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder => new \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder(
                static fn (string $key): string => trans_message($key),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer => new \App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer(
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder::class),
                maxFindings: (int) config('estimate-generation.project_planning.max_findings'),
                maxPackages: (int) config('estimate-generation.project_planning.max_work_packages'),
                maxEvidence: (int) config('estimate-generation.project_planning.max_finding_evidence'),
                maxRules: (int) config('estimate-generation.project_planning.max_completeness_rules'),
                maxEvidenceBytes: (int) config('estimate-generation.project_planning.max_finding_evidence_bytes'),
                translate: static fn (string $key): string => trans_message($key),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator => new \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator(
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog::class),
                maxFacts: (int) config('estimate-generation.project_planning.max_facts'),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline::class,
        );
        $this->app->singleton(EloquentEvaluationCorpusRepository::class, fn ($app) => new EloquentEvaluationCorpusRepository(
            $app->make('db')->connection(),
        ));
        $this->app->singleton(EvaluationCorpusRepository::class, EloquentEvaluationCorpusRepository::class);
        $this->app->singleton(EvaluationCorpus::class);
        $this->app->singleton(EvaluationReleaseGate::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource::class, \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EloquentNormativeContextPinSource::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver::class, \App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentSessionBaseInputVersionResolver::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver::class, fn ($app) => new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource::class),
            $app->make('db')->connection(),
        ));
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService::class, fn ($app) => new \App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Services\AuthoritativePackagePricingGuard::class),
            baseInputVersions: $app->make(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver::class),
        ));
        $this->app->singleton(EvidenceSourceReplacementInvalidator::class, EvidenceDocumentSourceReplacementInvalidator::class);
        $this->app->singleton(SessionStateStore::class, EloquentSessionStateStore::class);
        $this->app->singleton(AiEstimateQuotaService::class, fn ($app) => new AiEstimateQuotaService(
            $app->make('db')->connection(),
            $app->make(CommercialQuotaService::class),
        ));
        $this->app->singleton(AdvanceEstimateGeneration::class, fn ($app) => new AdvanceEstimateGeneration(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow::class),
            $app->make(AiEstimateQuotaService::class),
        ));
        $this->app->singleton(OcrClientInterface::class, static fn ($app): TimewebVisionOcrClient => new TimewebVisionOcrClient(
            $app->make(AiUsageStore::class),
            $app->make(EffectiveSettingsResolver::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Settings\DocumentRuntimeLimits::class),
        ));
        $this->app->singleton(AiUsageStore::class, EloquentAiUsageStore::class);
        $this->app->singleton(AiRoleRunRepository::class, static fn ($app): AiRoleRunRepository => new EloquentAiRoleRunRepository(
            $app->make('db')->connection(),
            (int) config('estimate-generation.generation.ai_role_run_lease_seconds', 180),
        ));
        $this->app->singleton(ObserverInputBuilder::class);
        $this->app->singleton(ArbitrationInputBuilder::class);
        $this->app->singleton(RunIndependentObservers::class, static fn ($app): RunIndependentObservers => new RunIndependentObservers(
            $app->make(AiRoleRunRepository::class),
            $app->make(VisionProvider::class),
            $app->make(ObserverInputBuilder::class),
            (string) config('estimate-generation.vision.model'),
        ));
        $this->app->alias(RunIndependentObservers::class, DocumentObserverRunner::class);
        $this->app->singleton(RunDocumentArbitration::class, static fn ($app): RunDocumentArbitration => new RunDocumentArbitration(
            $app->make(AiRoleRunRepository::class),
            $app->make(VisionProvider::class),
            $app->make(ArbitrationInputBuilder::class),
            (string) config('estimate-generation.vision.model'),
        ));
        $this->app->alias(RunDocumentArbitration::class, DocumentArbitrator::class);
        $this->app->singleton(ProjectSynthesisModel::class, static fn ($app): ProjectSynthesisModel => new TimewebProjectSynthesisModel(
            $app->make(RerankWireClient::class),
            $app->make(AiUsageStore::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
            (string) config('estimate-generation.project_engineer.model'),
            (int) config('estimate-generation.project_engineer.max_input_bytes'),
            (int) config('estimate-generation.project_engineer.max_output_tokens'),
            (int) config('estimate-generation.project_engineer.timeout_seconds'),
        ));
        $this->app->singleton(RunProjectSynthesis::class, static fn ($app): RunProjectSynthesis => new RunProjectSynthesis(
            $app->make(AiRoleRunRepository::class),
            $app->make(ProjectSynthesisModel::class),
            (string) config('estimate-generation.project_engineer.model'),
        ));
        $this->app->alias(RunProjectSynthesis::class, ProjectSynthesisRunner::class);
        $this->app->singleton(TimewebEstimateComposerModel::class, static fn ($app): TimewebEstimateComposerModel => new TimewebEstimateComposerModel(
            $app->make(RerankWireClient::class),
            $app->make(AiUsageStore::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
            (string) config('estimate-generation.estimate_composer.model'),
            (int) config('estimate-generation.estimate_composer.max_input_bytes'),
            (int) config('estimate-generation.estimate_composer.max_output_tokens'),
            (int) config('estimate-generation.estimate_composer.timeout_seconds'),
        ));
        $this->app->alias(TimewebEstimateComposerModel::class, EstimateComposerModel::class);
        $this->app->alias(
            TimewebEstimateComposerModel::class,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel::class,
        );
        $this->app->singleton(RunEstimateComposer::class, static fn ($app): RunEstimateComposer => new RunEstimateComposer(
            $app->make(AiRoleRunRepository::class),
            $app->make(EstimateComposerModel::class),
            (string) config('estimate-generation.estimate_composer.model'),
        ));
        $this->app->singleton(EstimateComposerInputFactory::class, static fn ($app): EstimateComposerInputFactory => new EstimateComposerInputFactory(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
            (int) config('estimate-generation.estimate_composer.max_facts'),
        ));
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection => new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection(
                $app->make(AiRoleRunRepository::class),
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel::class),
                (string) config('estimate-generation.estimate_composer.model'),
            ),
        );
        $this->app->singleton(EstimateAuditModel::class, static fn ($app): EstimateAuditModel => new TimewebEstimateAuditModel(
            $app->make(RerankWireClient::class),
            $app->make(AiUsageStore::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver::class),
            (string) config('estimate-generation.estimate_auditor.model'),
            (int) config('estimate-generation.estimate_auditor.max_input_bytes'),
            (int) config('estimate-generation.estimate_auditor.max_output_tokens'),
            (int) config('estimate-generation.estimate_auditor.timeout_seconds'),
        ));
        $this->app->singleton(RunEstimateAudit::class, static fn ($app): RunEstimateAudit => new RunEstimateAudit(
            $app->make(AiRoleRunRepository::class),
            $app->make(EstimateAuditModel::class),
            (string) config('estimate-generation.estimate_auditor.model'),
        ));
        $this->app->singleton(EstimateAuditInputFactory::class, static fn ($app): EstimateAuditInputFactory => new EstimateAuditInputFactory(
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class),
            $app->make(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class),
            (int) config('estimate-generation.estimate_auditor.max_facts'),
        ));
        $this->app->singleton(ApplyComposerCorrectionCycle::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore::class,
            \App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore::class,
        );
        $this->app->singleton(FailureStore::class, EloquentFailureStore::class);
        $this->app->singleton(FailureRecorderObserver::class, SafeLogFailureRecorderObserver::class);
        $this->app->singleton(PipelineCompletionHook::class, PublishValidatedDraft::class);
        $this->app->singleton(PublishDraftOnce::class, EloquentPublishDraftOnce::class);
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
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimateReviewExceptionSource::class,
            \App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Review\ProjectModelEstimateReviewExceptionSource::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions::class,
            static fn ($app): \App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions => new \App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions(
                $app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimateReviewExceptionSource::class),
                hash('sha256', (string) config('app.key').'|estimate-review-exceptions-v1'),
            ),
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpreter::class,
            \App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\ExistingProviderEstimateCommandInterpreter::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateDialogueContextSnapshotRepository::class,
            \App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EloquentEstimateDialogueContextSnapshotRepository::class,
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandContextBuilder::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CanonicalEstimateCommandProposalResolver::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeSimulation::class,
            \App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\DeterministicEstimateChangePreview::class,
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateInterpretationAttemptRepository::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalMutationExecutor::class,
            \App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\CanonicalEstimateProposalMutationExecutor::class,
        );
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalVersionFence::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\PreviewEstimateChange::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal::class);
        $this->app->singleton(\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CancelEstimateChangeProposal::class);
        $this->app->singleton(GeneratedEstimateNumberAllocator::class, LaravelGeneratedEstimateNumberAllocator::class);
        $this->app->singleton(RetryableEstimateGenerationSessionRepository::class, EloquentRetryableEstimateGenerationSessionRepository::class);
        $this->app->singleton(EstimateGenerationRetryDispatcher::class, LaravelEstimateGenerationRetryDispatcher::class);
        $this->app->singleton(EstimateGenerationSessionReconciler::class, ReconcileEstimateGenerationDocuments::class);
        $this->app->singleton(DocumentMutationSessionReconciler::class, ReconcileEstimateGenerationDocuments::class);
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationAuthorizer::class,
            \App\BusinessModules\Addons\EstimateGeneration\Operations\SystemAdminSessionOperationAuthorizer::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationTransaction::class,
            \App\BusinessModules\Addons\EstimateGeneration\Operations\EloquentAdminSessionOperationTransaction::class,
        );
        $this->app->singleton(
            \App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationExecutor::class,
            \App\BusinessModules\Addons\EstimateGeneration\Operations\ApplicationAdminSessionOperationExecutor::class,
        );
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
        $this->app->singleton(FgiscsBuildingResourcePricePriority::class);
        $this->app->singleton(FgiscsBuildingResourcePriceUpdateService::class);
        $this->app->singleton(FgiscsRegionalPriceSynchronizationService::class);
        $this->app->singleton(RegionalPriceQualityService::class);
        $this->app->singleton(RegionalPriceActivationService::class);
        $this->app->singleton(RegionalPriceImportLifecycleService::class);
        $this->app->singleton(RegionalPriceVersionResolver::class);
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
                    ->call(fn (): int => $this->app->make(RecoverStalledEstimateGenerationDocuments::class)->handle())
                    ->name('estimate-generation:recover-stalled-documents')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->onOneServer();
                $this->app->make(Schedule::class)
                    ->job(new RecoverEstimateGenerationPipelinesJob)
                    ->everyMinute()
                    ->withoutOverlapping();
            });
            $this->commands([
                BootstrapEstimateGenerationLearningCommand::class,
                InspectEstimateGenerationProductionCommand::class,
                InspectCadRuntimeReadinessCommand::class,
                RunEstimateGenerationBenchmarkCommand::class,
                RunEstimateGenerationBenchmarkCaseCommand::class,
                RunEvaluationReleaseGateCommand::class,
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

    }
}
