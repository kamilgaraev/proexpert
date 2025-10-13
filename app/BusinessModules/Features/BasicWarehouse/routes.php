<?php

use App\BusinessModules\Features\BasicWarehouse\Controllers\InventoryController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseOperationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Basic Warehouse Module Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['api', 'auth:api', 'organization.context'])
    ->prefix('api/v1')
    ->name('api.v1.')
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
        });
    });

