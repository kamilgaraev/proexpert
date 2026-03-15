<?php

use App\BusinessModules\Features\BasicWarehouse\Controllers\AssetController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\InventoryController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\ProjectAllocationController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehousePhotoController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseIdentifierController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseLogisticUnitController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseOperationsController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseScanEventController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseTaskController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\AdvancedWarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseStorageCellController;
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
            Route::get('/{id}', [WarehouseController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [WarehouseController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [WarehouseController::class, 'destroy'])->whereNumber('id');
            
            // Остатки и движения
            Route::get('/{id}/balances', [WarehouseController::class, 'balances'])->whereNumber('id');
            Route::get('/{id}/movements', [WarehouseController::class, 'movements'])->whereNumber('id');
            Route::get('/{warehouseId}/balances/{materialId}/photos', [WarehousePhotoController::class, 'balancePhotos']);
            Route::post('/{warehouseId}/balances/{materialId}/photos', [WarehousePhotoController::class, 'uploadBalancePhotos']);
            Route::delete('/{warehouseId}/balances/{materialId}/photos/{fileId}', [WarehousePhotoController::class, 'deleteBalancePhoto']);
            
            // Экспорт документов движений
            Route::get('/movements/{id}/export-m4', [WarehouseOperationsController::class, 'exportM4'])
                ->name('movements.export-m4');
            Route::get('/movements/{id}/photos', [WarehousePhotoController::class, 'movementPhotos'])
                ->name('movements.photos.index');
            Route::post('/movements/{id}/photos', [WarehousePhotoController::class, 'uploadMovementPhotos'])
                ->name('movements.photos.store');
            Route::delete('/movements/{id}/photos/{fileId}', [WarehousePhotoController::class, 'deleteMovementPhoto'])
                ->name('movements.photos.destroy');
            Route::get('/movements/{id}/export-m11', [WarehouseOperationsController::class, 'exportM11'])
                ->name('movements.export-m11');
            Route::get('/movements/{id}/export-m15', [WarehouseOperationsController::class, 'exportM15'])
                ->name('movements.export-m15');
            Route::get('/movements/{id}/export-m7', [WarehouseOperationsController::class, 'exportM7'])
                ->name('movements.export-m7');
            
            // Карточка учета материала
            Route::get('/materials/{materialId}/export-m17', [WarehouseOperationsController::class, 'exportM17'])
                ->name('materials.export-m17');
            
            // Операции со складом
            Route::post('/operations/receipt', [WarehouseOperationsController::class, 'receipt'])
                ->name('operations.receipt');
            Route::post('/operations/write-off', [WarehouseOperationsController::class, 'writeOff'])
                ->name('operations.write-off');
            Route::post('/operations/transfer', [WarehouseOperationsController::class, 'transfer'])
                ->name('operations.transfer');
            Route::post('/operations/reserve', [WarehouseOperationsController::class, 'reserve'])
                ->name('operations.reserve');
            Route::post('/operations/unreserve', [WarehouseOperationsController::class, 'unreserve'])
                ->name('operations.unreserve');
            Route::post('/operations/transfer-to-contractor', [WarehouseOperationsController::class, 'transferToContractor'])
                ->name('operations.transfer-to-contractor');
            
            // Инвентаризация
            Route::prefix('inventory')->name('inventory.')->group(function () {
                Route::get('/', [InventoryController::class, 'index']);
                Route::post('/', [InventoryController::class, 'store']);
                Route::get('/{id}', [InventoryController::class, 'show']);
                Route::post('/{id}/start', [InventoryController::class, 'start']);
                Route::put('/{actId}/items/{itemId}', [InventoryController::class, 'updateItem']);
                Route::post('/{id}/complete', [InventoryController::class, 'complete']);
                Route::post('/{id}/approve', [InventoryController::class, 'approve']);
                Route::get('/{id}/export', [InventoryController::class, 'export'])
                    ->name('export');
            });

            // Зоны хранения
            Route::prefix('{warehouseId}/zones')->name('zones.')->group(function () {
                Route::get('/', [WarehouseZoneController::class, 'index']);
                Route::post('/', [WarehouseZoneController::class, 'store']);
                Route::get('/{id}', [WarehouseZoneController::class, 'show']);
                Route::put('/{id}', [WarehouseZoneController::class, 'update']);
                Route::delete('/{id}', [WarehouseZoneController::class, 'destroy']);
            });

            Route::prefix('{warehouseId}/cells')->name('cells.')->group(function () {
                Route::get('/', [WarehouseStorageCellController::class, 'index']);
                Route::post('/', [WarehouseStorageCellController::class, 'store']);
                Route::get('/{id}', [WarehouseStorageCellController::class, 'show']);
                Route::put('/{id}', [WarehouseStorageCellController::class, 'update']);
                Route::delete('/{id}', [WarehouseStorageCellController::class, 'destroy']);
            });

            Route::prefix('{warehouseId}/logistic-units')->name('logistic-units.')->group(function () {
                Route::get('/', [WarehouseLogisticUnitController::class, 'index']);
                Route::post('/', [WarehouseLogisticUnitController::class, 'store']);
                Route::get('/{id}', [WarehouseLogisticUnitController::class, 'show']);
                Route::put('/{id}', [WarehouseLogisticUnitController::class, 'update']);
                Route::delete('/{id}', [WarehouseLogisticUnitController::class, 'destroy']);
            });

            Route::prefix('{warehouseId}/tasks')->name('tasks.')->group(function () {
                Route::get('/', [WarehouseTaskController::class, 'index']);
                Route::post('/', [WarehouseTaskController::class, 'store']);
                Route::get('/{id}', [WarehouseTaskController::class, 'show']);
                Route::put('/{id}', [WarehouseTaskController::class, 'update']);
                Route::post('/{id}/status', [WarehouseTaskController::class, 'updateStatus']);
                Route::delete('/{id}', [WarehouseTaskController::class, 'destroy']);
            });
        });

        Route::prefix('warehouse-identifiers')->name('warehouse-identifiers.')->group(function () {
            Route::post('/resolve', [WarehouseIdentifierController::class, 'resolve'])
                ->name('resolve');
            Route::get('/', [WarehouseIdentifierController::class, 'index'])
                ->name('index');
            Route::post('/', [WarehouseIdentifierController::class, 'store'])
                ->name('store');
            Route::get('/{id}', [WarehouseIdentifierController::class, 'show'])
                ->name('show');
            Route::put('/{id}', [WarehouseIdentifierController::class, 'update'])
                ->name('update');
            Route::delete('/{id}', [WarehouseIdentifierController::class, 'destroy'])
                ->name('destroy');
        });

        Route::prefix('warehouse-scan-events')->name('warehouse-scan-events.')->group(function () {
            Route::get('/', [WarehouseScanEventController::class, 'index'])
                ->name('index');
            Route::post('/', [WarehouseScanEventController::class, 'store'])
                ->name('store');
        });

        // Управление активами (по типам: материалы, расходники, оборудование и т.д.)
        Route::prefix('assets')->name('assets.')->group(function () {
            Route::get('/types', [AssetController::class, 'types'])->name('types');
            Route::get('/statistics', [AssetController::class, 'statistics'])->name('statistics');
            Route::post('/export-labels-pdf', [AssetController::class, 'exportLabelsPdf'])->name('export-labels-pdf');
            Route::get('/', [AssetController::class, 'index'])->name('index');
            Route::post('/', [AssetController::class, 'store'])->name('store');
            Route::get('/{id}', [AssetController::class, 'show'])->name('show');
            Route::put('/{id}', [AssetController::class, 'update'])->name('update');
            Route::delete('/{id}', [AssetController::class, 'destroy'])->name('destroy');
            Route::get('/{id}/photos', [WarehousePhotoController::class, 'assetPhotos'])->name('photos.index');
            Route::post('/{id}/photos', [WarehousePhotoController::class, 'uploadAssetPhotos'])->name('photos.store');
            Route::delete('/{id}/photos/{fileId}', [WarehousePhotoController::class, 'deleteAssetPhoto'])->name('photos.destroy');
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
            Route::get('/reservations/{reservationId}/export-m8', [WarehouseOperationsController::class, 'exportM8'])
                ->name('reservations.export-m8');
            
            // Автопополнение
            Route::post('/auto-reorder/rules', [AdvancedWarehouseController::class, 'createAutoReorderRule'])
                ->name('auto-reorder.create-rule');
            Route::get('/auto-reorder/rules', [AdvancedWarehouseController::class, 'autoReorderRules'])
                ->name('auto-reorder.rules');
            Route::put('/auto-reorder/rules/{ruleId}', [AdvancedWarehouseController::class, 'updateAutoReorderRule'])
                ->name('auto-reorder.update-rule');
            Route::delete('/auto-reorder/rules/{ruleId}', [AdvancedWarehouseController::class, 'deleteAutoReorderRule'])
                ->name('auto-reorder.delete-rule');
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

