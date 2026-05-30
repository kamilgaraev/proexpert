<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\ClassifyEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\ImportEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\InspectEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\QualityEstimateNormativesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\RollbackRegionalPricePeriodCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\SyncFgiscsBuildingResourcePricesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands\SyncFgiscsRegionalPricesCommand;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsClient;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
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
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSearchService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\RuleBasedNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\ConstructionDocumentFactExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentFactMerger;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\YandexCloudOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrPreflightService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrQualityAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrUsageLogger;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\ServiceProvider;

class EstimateGenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('estimate-generation.php'), 'estimate-generation');

        $this->app->singleton(DocumentParsingService::class);
        $this->app->singleton(OcrClientInterface::class, YandexCloudOcrClient::class);
        $this->app->singleton(OcrDocumentStorageService::class);
        $this->app->singleton(OcrPreflightService::class);
        $this->app->singleton(OcrUsageLogger::class);
        $this->app->singleton(SpreadsheetDocumentExtractor::class);
        $this->app->singleton(DocumentGenerationReadinessService::class);
        $this->app->singleton(DocumentProcessingStatusService::class);
        $this->app->singleton(OcrQualityAnalyzer::class);
        $this->app->singleton(ConstructionDocumentFactExtractor::class);
        $this->app->singleton(DocumentFactMerger::class);
        $this->app->singleton(OcrDocumentProcessor::class);
        $this->app->singleton(ConstructionSemanticParser::class);
        $this->app->singleton(EstimateDecompositionService::class);
        $this->app->singleton(WorkItemGenerationService::class);
        $this->app->singleton(ResourceAssemblyService::class);
        $this->app->singleton(EstimatePricingService::class);
        $this->app->singleton(EstimateValidationService::class);
        $this->app->singleton(EstimateDraftPersistenceService::class);
        $this->app->singleton(NormativeScopeRuleCatalog::class);
        $this->app->singleton(WorkIntentClassifier::class);
        $this->app->singleton(NormativeCandidateSearchService::class);
        $this->app->singleton(EstimateGenerationLearningEvidenceService::class);
        $this->app->singleton(RuleBasedNormativeCandidateReranker::class);
        $this->app->singleton(LLMNormativeCandidateReranker::class, fn ($app) => new LLMNormativeCandidateReranker(
            $app->make(LLMProviderInterface::class),
            $app->make(RuleBasedNormativeCandidateReranker::class)
        ));
        $this->app->singleton(NormativeCandidateRerankerInterface::class, function ($app): NormativeCandidateRerankerInterface {
            $provider = (string) config('estimate-generation.normative_matching.reranker.provider', 'rule_based');
            $llmEnabled = (bool) config('estimate-generation.normative_matching.reranker.llm_enabled', false);

            return $provider === 'llm' && $llmEnabled
                ? $app->make(LLMNormativeCandidateReranker::class)
                : $app->make(RuleBasedNormativeCandidateReranker::class);
        });
        $this->app->singleton(EstimateGenerationExcelExportService::class, fn () => new EstimateGenerationExcelExportService(
            app(ExcelEstimateBuilder::class)
        ));
        $this->app->singleton(EstimateGenerationOrchestrator::class);
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
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        $routesPath = __DIR__ . '/routes.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClassifyEstimateNormativesCommand::class,
                ImportEstimateNormativesCommand::class,
                InspectEstimateNormativesCommand::class,
                QualityEstimateNormativesCommand::class,
                SyncFgiscsRegionalPricesCommand::class,
                SyncFgiscsBuildingResourcePricesCommand::class,
                RollbackRegionalPricePeriodCommand::class,
            ]);
        }
    }
}
