<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement;

use App\BusinessModules\Features\ContractManagement\Http\Controllers\ContractEstimateItemController;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ContractManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractManagementModule::class);
        $this->app->singleton(ContractEstimateService::class);
    }

    public function boot(): void
    {
        Route::middleware([
            'api', 
            'auth:api_admin', 
            'auth.jwt:api_admin', 
            'organization.context', 
            'authorize:admin.access', 
            'interface:admin'
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

