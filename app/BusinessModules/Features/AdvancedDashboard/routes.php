<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\AdvancedDashboardController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\DashboardManagementController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\AlertsController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\ExportController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\WidgetsRegistryController;

/*
|--------------------------------------------------------------------------
| Advanced Dashboard Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля продвинутого дашборда
| Все маршруты защищены middleware advanced_dashboard.active
|
*/

Route::prefix('api/v1/admin/advanced-dashboard')
    ->name('admin.advanced_dashboard.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'advanced_dashboard.active'])
    ->group(function () {
        
        // Widgets Registry
        Route::get('/widgets/registry', [WidgetsRegistryController::class, 'getRegistry'])->name('widgets.registry');
        Route::get('/widgets/{widgetId}/info', [WidgetsRegistryController::class, 'getWidgetInfo'])->name('widgets.info');
        
        // Dashboard Management
        Route::prefix('dashboards')->name('dashboards.')->group(function () {
            Route::get('/', [DashboardManagementController::class, 'index'])->name('index');
            Route::post('/', [DashboardManagementController::class, 'store'])->name('store');
            Route::post('/from-template', [DashboardManagementController::class, 'createFromTemplate'])->name('from_template');
            Route::get('/templates', [DashboardManagementController::class, 'templates'])->name('templates');
            Route::get('/{id}', [DashboardManagementController::class, 'show'])->name('show');
            Route::put('/{id}', [DashboardManagementController::class, 'update'])->name('update');
            Route::delete('/{id}', [DashboardManagementController::class, 'destroy'])->name('destroy');
            Route::put('/{id}/layout', [DashboardManagementController::class, 'updateLayout'])->name('update_layout');
            Route::put('/{id}/widgets', [DashboardManagementController::class, 'updateWidgets'])->name('update_widgets');
            Route::put('/{id}/filters', [DashboardManagementController::class, 'updateFilters'])->name('update_filters');
            Route::post('/{id}/duplicate', [DashboardManagementController::class, 'duplicate'])->name('duplicate');
            Route::post('/{id}/make-default', [DashboardManagementController::class, 'makeDefault'])->name('make_default');
            Route::post('/{id}/share', [DashboardManagementController::class, 'share'])->name('share');
            Route::delete('/{id}/share', [DashboardManagementController::class, 'unshare'])->name('unshare');
        });
        
        // Analytics endpoints
        Route::prefix('analytics')->name('analytics.')->group(function () {
            // Financial
            Route::get('/financial/cash-flow', [AdvancedDashboardController::class, 'getCashFlow'])->name('cash_flow');
            Route::get('/financial/profit-loss', [AdvancedDashboardController::class, 'getProfitLoss'])->name('profit_loss');
            Route::get('/financial/roi', [AdvancedDashboardController::class, 'getROI'])->name('roi');
            Route::get('/financial/revenue-forecast', [AdvancedDashboardController::class, 'getRevenueForecast'])->name('revenue_forecast');
            Route::get('/financial/receivables-payables', [AdvancedDashboardController::class, 'getReceivablesPayables'])->name('receivables_payables');
            
            // Predictive
            Route::get('/predictive/contract-forecast', [AdvancedDashboardController::class, 'getContractForecast'])->name('contract_forecast');
            Route::get('/predictive/budget-risk', [AdvancedDashboardController::class, 'getBudgetRisk'])->name('budget_risk');
            Route::get('/predictive/material-needs', [AdvancedDashboardController::class, 'getMaterialNeeds'])->name('material_needs');
            
            // HR/KPI
            Route::get('/hr/kpi', [AdvancedDashboardController::class, 'getKPI'])->name('kpi');
            Route::get('/hr/top-performers', [AdvancedDashboardController::class, 'getTopPerformers'])->name('top_performers');
            Route::get('/hr/resource-utilization', [AdvancedDashboardController::class, 'getResourceUtilization'])->name('resource_utilization');
        });
        
        // Alerts
        Route::prefix('alerts')->name('alerts.')->group(function () {
            Route::get('/', [AlertsController::class, 'index'])->name('index');
            Route::post('/', [AlertsController::class, 'store'])->name('store');
            Route::post('/check-all', [AlertsController::class, 'checkAll'])->name('check_all');
            Route::get('/{id}', [AlertsController::class, 'show'])->name('show');
            Route::put('/{id}', [AlertsController::class, 'update'])->name('update');
            Route::delete('/{id}', [AlertsController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/toggle', [AlertsController::class, 'toggle'])->name('toggle');
            Route::post('/{id}/reset', [AlertsController::class, 'reset'])->name('reset');
            Route::get('/{id}/history', [AlertsController::class, 'history'])->name('history');
        });
        
        // Export
        Route::prefix('export')->name('export.')->group(function () {
            Route::get('/formats', [ExportController::class, 'getAvailableFormats'])->name('formats');
            Route::post('/dashboard/{id}/pdf', [ExportController::class, 'exportToPDF'])->name('pdf');
            Route::post('/dashboard/{id}/excel', [ExportController::class, 'exportToExcel'])->name('excel');
            Route::get('/scheduled-reports', [ExportController::class, 'listScheduledReports'])->name('scheduled_reports');
            Route::post('/scheduled-reports', [ExportController::class, 'createScheduledReport'])->name('create_scheduled_report');
            Route::put('/scheduled-reports/{id}', [ExportController::class, 'updateScheduledReport'])->name('update_scheduled_report');
            Route::delete('/scheduled-reports/{id}', [ExportController::class, 'deleteScheduledReport'])->name('delete_scheduled_report');
        });
    });

