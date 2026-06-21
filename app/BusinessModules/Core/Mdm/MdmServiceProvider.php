<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm;

use App\BusinessModules\Core\Mdm\Console\Commands\MdmInspectCommand;
use App\BusinessModules\Core\Mdm\Console\Commands\MdmSyncCommand;
use App\BusinessModules\Core\Mdm\Observers\MdmCatalogObserver;
use App\BusinessModules\Core\Mdm\Services\MdmChangeRequestService;
use App\BusinessModules\Core\Mdm\Services\MdmDiffService;
use App\BusinessModules\Core\Mdm\Services\MdmDomainChangeApplier;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmEntityGovernanceRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmImpactAnalysisService;
use App\BusinessModules\Core\Mdm\Services\MdmImportService;
use App\BusinessModules\Core\Mdm\Services\MdmMergePlanner;
use App\BusinessModules\Core\Mdm\Services\MdmMergeService;
use App\BusinessModules\Core\Mdm\Services\MdmNormalizationService;
use App\BusinessModules\Core\Mdm\Services\MdmOneCLockService;
use App\BusinessModules\Core\Mdm\Services\MdmQualityPolicyService;
use App\BusinessModules\Core\Mdm\Services\MdmQualityService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipSourceRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmSimilarityService;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\CostCategory;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionCatalogCategory;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\WorkType;
use Illuminate\Support\ServiceProvider;

class MdmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MdmEntityRegistry::class);
        $this->app->singleton(MdmEntityGovernanceRegistry::class);
        $this->app->singleton(MdmDiffService::class);
        $this->app->singleton(MdmImpactAnalysisService::class);
        $this->app->singleton(MdmOneCLockService::class);
        $this->app->singleton(MdmDomainChangeApplier::class);
        $this->app->singleton(MdmNormalizationService::class);
        $this->app->singleton(MdmQualityPolicyService::class);
        $this->app->singleton(MdmQualityService::class);
        $this->app->singleton(MdmSimilarityService::class);
        $this->app->singleton(MdmRecordService::class);
        $this->app->singleton(MdmChangeRequestService::class);
        $this->app->singleton(MdmDuplicateDetectionService::class);
        $this->app->singleton(MdmRelationshipSourceRegistry::class);
        $this->app->singleton(MdmRelationshipService::class);
        $this->app->singleton(MdmImportService::class);
        $this->app->singleton(MdmMergePlanner::class);
        $this->app->singleton(MdmMergeService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MdmInspectCommand::class,
                MdmSyncCommand::class,
            ]);
        }

        foreach ([
            Contractor::class,
            Supplier::class,
            Material::class,
            MeasurementUnit::class,
            WorkType::class,
            CostCategory::class,
            EstimatePositionCatalog::class,
            EstimatePositionCatalogCategory::class,
            BudgetArticle::class,
            ResponsibilityCenter::class,
            Project::class,
            Contract::class,
        ] as $model) {
            $model::observe(MdmCatalogObserver::class);
        }
    }
}
