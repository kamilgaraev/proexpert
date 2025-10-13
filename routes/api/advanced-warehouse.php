<?php

use App\BusinessModules\Features\AdvancedWarehouse\Controllers\AdvancedWarehouseController;
use App\BusinessModules\Features\AdvancedWarehouse\Controllers\WarehouseZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Advanced Warehouse API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'organization.context', 'module:advanced-warehouse'])->prefix('advanced-warehouse')->group(function () {
    
    // Аналитика и прогнозирование
    Route::get('/analytics/turnover', [AdvancedWarehouseController::class, 'turnoverAnalytics'])->name('advanced-warehouse.analytics.turnover');
    Route::get('/analytics/forecast', [AdvancedWarehouseController::class, 'forecast'])->name('advanced-warehouse.analytics.forecast');
    Route::get('/analytics/abc-xyz', [AdvancedWarehouseController::class, 'abcXyzAnalysis'])->name('advanced-warehouse.analytics.abc-xyz');
    
    // Резервирование
    Route::post('/reservations', [AdvancedWarehouseController::class, 'reserve'])->name('advanced-warehouse.reservations.create');
    Route::get('/reservations', [AdvancedWarehouseController::class, 'reservations'])->name('advanced-warehouse.reservations.index');
    Route::delete('/reservations/{reservationId}', [AdvancedWarehouseController::class, 'unreserve'])->name('advanced-warehouse.reservations.unreserve');
    
    // Автопополнение
    Route::post('/auto-reorder/rules', [AdvancedWarehouseController::class, 'createAutoReorderRule'])->name('advanced-warehouse.auto-reorder.create-rule');
    Route::get('/auto-reorder/rules', [AdvancedWarehouseController::class, 'autoReorderRules'])->name('advanced-warehouse.auto-reorder.rules');
    Route::post('/auto-reorder/check', [AdvancedWarehouseController::class, 'checkAutoReorder'])->name('advanced-warehouse.auto-reorder.check');
    
    // Зоны хранения
    Route::prefix('warehouses/{warehouseId}/zones')->group(function () {
        Route::get('/', [WarehouseZoneController::class, 'index'])->name('advanced-warehouse.zones.index');
        Route::post('/', [WarehouseZoneController::class, 'store'])->name('advanced-warehouse.zones.store');
        Route::get('/{id}', [WarehouseZoneController::class, 'show'])->name('advanced-warehouse.zones.show');
        Route::put('/{id}', [WarehouseZoneController::class, 'update'])->name('advanced-warehouse.zones.update');
        Route::delete('/{id}', [WarehouseZoneController::class, 'destroy'])->name('advanced-warehouse.zones.destroy');
    });
});

