<?php

use App\BusinessModules\Features\BasicWarehouse\Controllers\InventoryController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\ProjectAllocationController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseOperationsController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\AdvancedWarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Basic Warehouse Module Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// Admin Panel Routes
// ==========================================
Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('api/v1/admin')
    ->name('admin.')
    ->group(function () {
        
        Route::prefix('warehouses')->name('warehouses.')->group(function () {
            
            // Управление складами
            Route::get('/', [WarehouseController::class, 'index']);
            Route::post('/', [WarehouseController::class, 'store']);
            Route::get('/{id}', [WarehouseController::class, 'show']);
            Route::put('/{id}', [WarehouseController::class, 'update']);
            Route::delete('/{id}', [WarehouseController::class, 'destroy']);
            
            // Остатки и движения
            Route::get('/{id}/balances', [WarehouseController::class, 'balances']);
            Route::get('/{id}/movements', [WarehouseController::class, 'movements']);
            
            // Операции со складом
            Route::post('/operations/receipt', [WarehouseOperationsController::class, 'receipt'])
                ->name('operations.receipt');
            Route::post('/operations/write-off', [WarehouseOperationsController::class, 'writeOff'])
                ->name('operations.write-off');
            Route::post('/operations/transfer', [WarehouseOperationsController::class, 'transfer'])
                ->name('operations.transfer');
            
            // Инвентаризация
            Route::prefix('inventory')->name('inventory.')->group(function () {
                Route::get('/', [InventoryController::class, 'index']);
                Route::post('/', [InventoryController::class, 'store']);
                Route::get('/{id}', [InventoryController::class, 'show']);
                Route::post('/{id}/start', [InventoryController::class, 'start']);
                Route::put('/{actId}/items/{itemId}', [InventoryController::class, 'updateItem']);
                Route::post('/{id}/complete', [InventoryController::class, 'complete']);
                Route::post('/{id}/approve', [InventoryController::class, 'approve']);
            });

            // Зоны хранения
            Route::prefix('{warehouseId}/zones')->name('zones.')->group(function () {
                Route::get('/', [WarehouseZoneController::class, 'index']);
                Route::post('/', [WarehouseZoneController::class, 'store']);
                Route::get('/{id}', [WarehouseZoneController::class, 'show']);
                Route::put('/{id}', [WarehouseZoneController::class, 'update']);
                Route::delete('/{id}', [WarehouseZoneController::class, 'destroy']);
            });
        });

        // Продвинутые функции (Аналитика, Резервирование, Автозаказ)
        Route::prefix('advanced-warehouse')->name('advanced-warehouse.')->group(function () {
            
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
        });
        
        // Распределение материалов по проектам
        Route::prefix('project-allocations')->name('project-allocations.')->group(function () {
            Route::post('/', [ProjectAllocationController::class, 'allocate'])
                ->name('allocate');
            Route::delete('/{id}', [ProjectAllocationController::class, 'deallocate'])
                ->name('deallocate');
            Route::get('/project/{projectId}', [ProjectAllocationController::class, 'getProjectAllocations'])
                ->name('project');
        });
    });

