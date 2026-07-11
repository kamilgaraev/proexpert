<?php

declare(strict_types=1);

namespace App\Providers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\OrdinaryEstimateNumberLookup;
use App\BusinessModules\Addons\EstimateGeneration\Application\Export\EstimateGenerationExporter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Learning\EstimateGenerationLearningBootstrapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ImportedEstimateExampleExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingEstimateRowsReader;
use App\Integrations\EstimateGeneration\BudgetEstimateTrainingRowsReader;
use App\Integrations\EstimateGeneration\EloquentOrdinaryEstimateNumberLookup;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Integrations\EstimateGeneration\EstimateGenerationLearningBootstrapService;
use App\Integrations\EstimateGeneration\EstimateLearningExampleExtractor;
use Illuminate\Support\ServiceProvider;

final class EstimateGenerationIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrdinaryEstimateNumberLookup::class, EloquentOrdinaryEstimateNumberLookup::class);
        $this->app->singleton(EstimateGenerationExporter::class, EstimateGenerationExcelExportService::class);
        $this->app->singleton(EstimateGenerationLearningBootstrapper::class, EstimateGenerationLearningBootstrapService::class);
        $this->app->singleton(ImportedEstimateExampleExtractor::class, EstimateLearningExampleExtractor::class);
        $this->app->singleton(TrainingEstimateRowsReader::class, BudgetEstimateTrainingRowsReader::class);
    }
}
