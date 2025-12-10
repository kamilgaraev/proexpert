<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DashboardSettingsController;
use App\Http\Controllers\Api\V1\Admin\DashboardEVMController;
use App\Http\Controllers\Api\V1\Admin\DashboardMapController;

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Все маршруты дашборда используют более щадящий rate limiter 'dashboard'
| т.к. при загрузке страницы фронтенд делает ~12 параллельных запросов
|
*/

Route::middleware('throttle:dashboard')->group(function () {
    
    // УПРОЩЕННЫЙ ДАШБОРД - один эндпоинт возвращает всю структуру
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index'); // ?project_id=123
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary'); // для обратной совместимости
    Route::get('/dashboard/timeseries', [DashboardController::class, 'timeseries'])->name('dashboard.timeseries');
    Route::get('/dashboard/top-entities', [DashboardController::class, 'topEntities'])->name('dashboard.top-entities');
    Route::get('/dashboard/history', [DashboardController::class, 'history'])->name('dashboard.history');
    Route::get('/dashboard/limits', [DashboardController::class, 'limits'])->name('dashboard.limits');
    
    // EVM Metrics (Earned Value Management)
    Route::get('/dashboard/evm/metrics', [DashboardEVMController::class, 'metrics'])->name('dashboard.evm.metrics');
    Route::get('/dashboard/evm/forecast', [DashboardEVMController::class, 'forecast'])->name('dashboard.evm.forecast');
    
    // Map Data
    Route::get('/dashboard/map', [DashboardMapController::class, 'index'])->name('dashboard.map');

    // Endpoints для контрактов проекта (требуют project_id)
    Route::get('/dashboard/contracts/requiring-attention', [DashboardController::class, 'contractsRequiringAttention'])
        ->name('dashboard.contracts.requiring-attention'); // ?project_id=123
    Route::get('/dashboard/contracts/statistics', [DashboardController::class, 'contractsStatistics'])
        ->name('dashboard.contracts.statistics'); // ?project_id=123
    Route::get('/dashboard/contracts/top', [DashboardController::class, 'topContracts'])
        ->name('dashboard.contracts.top'); // ?project_id=123
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity'])
        ->name('dashboard.recent-activity'); // ?project_id=123
    
    // Статистика по заявкам (включая заявки на персонал)
    Route::get('/dashboard/site-requests/statistics', [DashboardController::class, 'siteRequestsStatistics'])
        ->name('dashboard.site-requests.statistics');
    
    // Новые эндпоинты для аналитики
    Route::get('/dashboard/financial-metrics', [DashboardController::class, 'financialMetrics'])->name('dashboard.financial-metrics');
    Route::get('/dashboard/projects-analytics', [DashboardController::class, 'projectsAnalytics'])->name('dashboard.projects-analytics');
    Route::get('/dashboard/materials-analytics', [DashboardController::class, 'materialsAnalytics'])->name('dashboard.materials-analytics');
    Route::get('/dashboard/comparison', [DashboardController::class, 'comparison'])->name('dashboard.comparison');
    
    // Эндпоинты для графиков
    Route::get('/dashboard/contracts-by-status', [DashboardController::class, 'contractsByStatus'])->name('dashboard.contracts-by-status');
    Route::get('/dashboard/projects-by-status', [DashboardController::class, 'projectsByStatus'])->name('dashboard.projects-by-status');
    Route::get('/dashboard/contracts-by-contractor', [DashboardController::class, 'contractsByContractor'])->name('dashboard.contracts-by-contractor');
    Route::get('/dashboard/materials-by-project', [DashboardController::class, 'materialsByProject'])->name('dashboard.materials-by-project');
    Route::get('/dashboard/materials-by-category', [DashboardController::class, 'materialsByCategory'])->name('dashboard.materials-by-category');
    Route::get('/dashboard/works-by-type', [DashboardController::class, 'worksByType'])->name('dashboard.works-by-type');
    
    // Эндпоинты для топ-листов
    Route::get('/dashboard/top-contracts', [DashboardController::class, 'topContracts'])->name('dashboard.top-contracts');
    Route::get('/dashboard/top-projects', [DashboardController::class, 'topProjects'])->name('dashboard.top-projects');
    Route::get('/dashboard/top-materials', [DashboardController::class, 'topMaterials'])->name('dashboard.top-materials');
    Route::get('/dashboard/top-contractors', [DashboardController::class, 'topContractors'])->name('dashboard.top-contractors');
    
    // Эндпоинты для детальной аналитики
    Route::get('/dashboard/completed-works-analytics', [DashboardController::class, 'completedWorksAnalytics'])->name('dashboard.completed-works-analytics');
    Route::get('/dashboard/monthly-trends', [DashboardController::class, 'monthlyTrends'])->name('dashboard.monthly-trends');
    Route::get('/dashboard/financial-flow', [DashboardController::class, 'financialFlow'])->name('dashboard.financial-flow');
    Route::get('/dashboard/contract-performance', [DashboardController::class, 'contractPerformance'])->name('dashboard.contract-performance');
    Route::get('/dashboard/project-progress', [DashboardController::class, 'projectProgress'])->name('dashboard.project-progress');
    Route::get('/dashboard/material-consumption', [DashboardController::class, 'materialConsumption'])->name('dashboard.material-consumption');
    Route::get('/dashboard/works-efficiency', [DashboardController::class, 'worksEfficiency'])->name('dashboard.works-efficiency');
    
    // Эндпоинты для экспорта
    Route::post('/dashboard/export/summary', [DashboardController::class, 'exportSummary'])->name('dashboard.export.summary');
    Route::post('/dashboard/export/contracts', [DashboardController::class, 'exportContracts'])->name('dashboard.export.contracts');
    Route::post('/dashboard/export/projects', [DashboardController::class, 'exportProjects'])->name('dashboard.export.projects');
    Route::post('/dashboard/export/materials', [DashboardController::class, 'exportMaterials'])->name('dashboard.export.materials');
    
    // Настройки виджетов и реестр
    Route::prefix('/dashboard')->group(function () {
        Route::get('/widgets', [DashboardSettingsController::class, 'widgets']);
        Route::get('/settings', [DashboardSettingsController::class, 'get']);
        Route::put('/settings', [DashboardSettingsController::class, 'put']);
        Route::delete('/settings', [DashboardSettingsController::class, 'delete']);
        Route::get('/settings/defaults', [DashboardSettingsController::class, 'defaults']);
        });
    
});
