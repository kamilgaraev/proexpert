<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DashboardSettingsController;
use App\Http\Controllers\Api\V1\Admin\DashboardEVMController;
use App\Http\Controllers\Api\V1\Admin\DashboardMapController;
use App\Http\Controllers\Api\V1\Admin\Geo\MapTileController;
use App\Http\Controllers\Api\V1\Admin\Geo\MapSearchController;
use App\Http\Controllers\Api\V1\Admin\Geo\MapLayerController;
use App\Http\Controllers\Api\V1\Admin\Geo\GeocodingController;

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
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index'); // ?project_id=123
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    
    // EVM Metrics (Earned Value Management)
    Route::get('/dashboard/evm/metrics', [DashboardEVMController::class, 'metrics'])->name('dashboard.evm.metrics');
    Route::get('/dashboard/evm/forecast', [DashboardEVMController::class, 'forecast'])->name('dashboard.evm.forecast');
    
    // Map Data (Legacy endpoint - kept for backward compatibility)
    Route::get('/dashboard/map', [DashboardMapController::class, 'index'])->name('dashboard.map');
    
    // New Tile-based Map Endpoints
    Route::prefix('/dashboard/map')->group(function () {
        // Tile system
        Route::get('/tiles/{z}/{x}/{y}', [MapTileController::class, 'getTile'])->name('dashboard.map.tiles');
        Route::get('/projects', [MapTileController::class, 'getProjects'])->name('dashboard.map.projects');
        
        // Search
        Route::get('/search', [MapSearchController::class, 'search'])->name('dashboard.map.search');
        Route::get('/search/nearby', [MapSearchController::class, 'searchNearby'])->name('dashboard.map.search.nearby');
        Route::get('/search/suggest', [MapSearchController::class, 'suggest'])->name('dashboard.map.search.suggest');
        
        // Layers
        Route::get('/layers/heatmap', [MapLayerController::class, 'getHeatmap'])->name('dashboard.map.layers.heatmap');
        Route::get('/layers/density', [MapLayerController::class, 'getDensity'])->name('dashboard.map.layers.density');
    });

    Route::get('/dashboard/financial-metrics', [DashboardController::class, 'financialMetrics'])->name('dashboard.financial-metrics');
    Route::get('/dashboard/projects-analytics', [DashboardController::class, 'projectsAnalytics'])->name('dashboard.projects-analytics');
    Route::post('/dashboard/export/projects', [DashboardController::class, 'exportProjects'])->name('dashboard.export.projects');
    
    // Настройки виджетов и реестр
    Route::prefix('/dashboard')->group(function () {
        Route::get('/widgets', [DashboardSettingsController::class, 'widgets']);
        Route::get('/settings', [DashboardSettingsController::class, 'get']);
        Route::put('/settings', [DashboardSettingsController::class, 'put']);
        Route::delete('/settings', [DashboardSettingsController::class, 'delete']);
        Route::get('/settings/defaults', [DashboardSettingsController::class, 'defaults']);
        });
    
});
