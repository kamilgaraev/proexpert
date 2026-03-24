<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\FileParserService;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        $routesPath = __DIR__ . '/routes.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }
}
