<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\ContractManagement\Http\Controllers\ContractEstimateItemController;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureProvider;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposurePublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureReportBindingFactory;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerVersionObserver;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementProjectionService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ContractManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractManagementModule::class);
        $this->app->singleton(ContractEstimateService::class);
        $this->app->singleton(ContractSettlementExposureCandidateContract::class);
        $this->app->scoped(ContractSettlementOwnerSource::class);
        $this->app->scoped(ContractSettlementProjectionService::class);
        $this->app->scoped(ContractSettlementQueryService::class);
        $this->app->scoped(ContractSettlementExposureProvider::class);
        $this->app->scoped(ContractSettlementExposureReportBindingFactory::class);
        $this->app->scoped(ContractSettlementExposurePublishedRuntimeBindingRegistrar::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        foreach ([
            Contract::class,
            ContractProjectAllocation::class,
            ContractPerformanceAct::class,
            PaymentDocument::class,
            PaymentTransaction::class,
        ] as $owner) {
            $owner::observe(ContractSettlementOwnerVersionObserver::class);
        }

        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app
                    ->make(ContractSettlementExposurePublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
            },
        );

        Route::middleware([
            'api',
            'auth:api_admin',
            'auth.jwt:api_admin',
            'organization.context',
            'authorize:admin.access',
            'interface:admin',
        ])
            ->prefix('api/v1/admin/contracts/{contract}/estimate-items')
            ->group(function () {
                Route::get('/', [ContractEstimateItemController::class, 'index']);
                Route::get('/available', [ContractEstimateItemController::class, 'available']);
                Route::get('/summary', [ContractEstimateItemController::class, 'summary']);
                Route::get('/project-estimates', [ContractEstimateItemController::class, 'projectEstimates']);
                Route::post('/attach', [ContractEstimateItemController::class, 'attach']);
                Route::delete('/detach', [ContractEstimateItemController::class, 'detach']);
            });
    }
}
