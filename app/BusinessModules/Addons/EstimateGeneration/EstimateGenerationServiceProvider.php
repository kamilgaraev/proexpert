<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\FileParserService;
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
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use Illuminate\Support\ServiceProvider;

class EstimateGenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentParsingService::class, fn () => new DocumentParsingService(
            app(FileParserService::class)
        ));
        $this->app->singleton(ConstructionSemanticParser::class);
        $this->app->singleton(EstimateDecompositionService::class);
        $this->app->singleton(WorkItemGenerationService::class);
        $this->app->singleton(ResourceAssemblyService::class);
        $this->app->singleton(EstimatePricingService::class);
        $this->app->singleton(EstimateValidationService::class);
        $this->app->singleton(EstimateDraftPersistenceService::class);
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
