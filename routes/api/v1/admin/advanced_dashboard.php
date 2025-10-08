<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\WidgetsController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\DashboardManagementController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\AlertsController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\ExportController;

Route::prefix('advanced-dashboard')->middleware(['auth:api', 'feature:advanced_dashboard'])->group(function () {
    
    Route::get('/widgets/{type}/data', [WidgetsController::class, 'getData'])->name('advanced-dashboard.widgets.data');
    
    Route::post('/widgets/batch', [WidgetsController::class, 'getBatch'])->name('advanced-dashboard.widgets.batch');
    
    Route::get('/widgets/{type}/metadata', [WidgetsController::class, 'getMetadata'])->name('advanced-dashboard.widgets.metadata');
    
    Route::get('/widgets/registry', [WidgetsController::class, 'getRegistry'])->name('advanced-dashboard.widgets.registry');
    
    Route::get('/widgets/categories', [WidgetsController::class, 'getCategories'])->name('advanced-dashboard.widgets.categories');

    Route::prefix('dashboards')->group(function () {
        Route::get('/', [DashboardManagementController::class, 'index'])->name('advanced-dashboard.dashboards.index');
        Route::post('/', [DashboardManagementController::class, 'store'])->name('advanced-dashboard.dashboards.store');
        Route::get('/{id}', [DashboardManagementController::class, 'show'])->name('advanced-dashboard.dashboards.show');
        Route::put('/{id}', [DashboardManagementController::class, 'update'])->name('advanced-dashboard.dashboards.update');
        Route::delete('/{id}', [DashboardManagementController::class, 'destroy'])->name('advanced-dashboard.dashboards.destroy');
        Route::post('/{id}/duplicate', [DashboardManagementController::class, 'duplicate'])->name('advanced-dashboard.dashboards.duplicate');
        Route::post('/{id}/set-default', [DashboardManagementController::class, 'setDefault'])->name('advanced-dashboard.dashboards.set-default');
    });

    Route::prefix('alerts')->group(function () {
        Route::get('/', [AlertsController::class, 'index'])->name('advanced-dashboard.alerts.index');
        Route::post('/', [AlertsController::class, 'store'])->name('advanced-dashboard.alerts.store');
        Route::get('/{id}', [AlertsController::class, 'show'])->name('advanced-dashboard.alerts.show');
        Route::put('/{id}', [AlertsController::class, 'update'])->name('advanced-dashboard.alerts.update');
        Route::delete('/{id}', [AlertsController::class, 'destroy'])->name('advanced-dashboard.alerts.destroy');
        Route::post('/{id}/test', [AlertsController::class, 'test'])->name('advanced-dashboard.alerts.test');
    });

    Route::prefix('export')->group(function () {
        Route::post('/dashboard/{id}', [ExportController::class, 'exportDashboard'])->name('advanced-dashboard.export.dashboard');
        Route::post('/widget/{type}', [ExportController::class, 'exportWidget'])->name('advanced-dashboard.export.widget');
        Route::post('/batch', [ExportController::class, 'exportBatch'])->name('advanced-dashboard.export.batch');
    });
});

