<?php

use App\BusinessModules\Features\AdvancedWarehouse\Controllers\AdvancedWarehouseController;
use App\BusinessModules\Features\AdvancedWarehouse\Controllers\WarehouseZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Advanced Warehouse Module Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['api', 'auth:api', 'organization.context', 'module:advanced-warehouse'])
    ->prefix('api/v1/advanced-warehouse')
    ->name('api.v1.advanced-warehouse.')
    ->group(function () {
        
        // Аналитика и прогнозирование
        Route::get('/analytics/turnover', [AdvancedWarehouseController::class, 'turnoverAnalytics'])
            ->name('analytics.turnover');
        Route::get('/analytics/forecast', [AdvancedWarehouseController::class, 'forecast'])
            ->name('analytics.forecast');
        Route::get('/analytics/abc-xyz', [AdvancedWarehouseController::class, 'abcXyzAnalysis'])
            ->name('analytics.abc-xyz');
        
        // Резервирование
        Route::post('/reservations', [AdvancedWarehouseController::class, 'reserve'])
            ->name('reservations.create');
        Route::get('/reservations', [AdvancedWarehouseController::class, 'reservations'])
            ->name('reservations.index');
        Route::delete('/reservations/{reservationId}', [AdvancedWarehouseController::class, 'unreserve'])
            ->name('reservations.unreserve');
        
        // Автопополнение
        Route::post('/auto-reorder/rules', [AdvancedWarehouseController::class, 'createAutoReorderRule'])
            ->name('auto-reorder.create-rule');
        Route::get('/auto-reorder/rules', [AdvancedWarehouseController::class, 'autoReorderRules'])
            ->name('auto-reorder.rules');
        Route::post('/auto-reorder/check', [AdvancedWarehouseController::class, 'checkAutoReorder'])
            ->name('auto-reorder.check');
        
        // Зоны хранения
        Route::prefix('warehouses/{warehouseId}/zones')->name('zones.')->group(function () {
            Route::get('/', [WarehouseZoneController::class, 'index']);
            Route::post('/', [WarehouseZoneController::class, 'store']);
            Route::get('/{id}', [WarehouseZoneController::class, 'show']);
            Route::put('/{id}', [WarehouseZoneController::class, 'update']);
            Route::delete('/{id}', [WarehouseZoneController::class, 'destroy']);
        });
    });

