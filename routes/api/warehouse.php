<?php

use App\BusinessModules\Features\BasicWarehouse\Controllers\InventoryController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseOperationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Warehouse API Routes (Basic Warehouse Module)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'organization.context'])->prefix('warehouses')->group(function () {
    
    // Управление складами
    Route::get('/', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('/', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::get('/{id}', [WarehouseController::class, 'show'])->name('warehouses.show');
    Route::put('/{id}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::delete('/{id}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
    
    // Остатки и движения
    Route::get('/{id}/balances', [WarehouseController::class, 'balances'])->name('warehouses.balances');
    Route::get('/{id}/movements', [WarehouseController::class, 'movements'])->name('warehouses.movements');
    
    // Операции со складом
    Route::post('/operations/receipt', [WarehouseOperationsController::class, 'receipt'])->name('warehouse.operations.receipt');
    Route::post('/operations/write-off', [WarehouseOperationsController::class, 'writeOff'])->name('warehouse.operations.write-off');
    Route::post('/operations/transfer', [WarehouseOperationsController::class, 'transfer'])->name('warehouse.operations.transfer');
    
    // Инвентаризация
    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/', [InventoryController::class, 'store'])->name('inventory.store');
        Route::get('/{id}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::post('/{id}/start', [InventoryController::class, 'start'])->name('inventory.start');
        Route::put('/{actId}/items/{itemId}', [InventoryController::class, 'updateItem'])->name('inventory.update-item');
        Route::post('/{id}/complete', [InventoryController::class, 'complete'])->name('inventory.complete');
        Route::post('/{id}/approve', [InventoryController::class, 'approve'])->name('inventory.approve');
    });
});

