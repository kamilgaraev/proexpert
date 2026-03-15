<?php

use App\Http\Controllers\Api\V1\Mobile\WarehouseScanController;
use App\Http\Controllers\Api\V1\Mobile\WarehouseController;
use App\Http\Controllers\Api\V1\Mobile\WarehouseTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/warehouse', [WarehouseController::class, 'index'])->name('warehouse.index');
    Route::get('/warehouse/warehouses/{warehouseId}/balances', [WarehouseController::class, 'balances'])->name('warehouse.balances');
    Route::get('/warehouse/warehouses/{warehouseId}/tasks', [WarehouseTaskController::class, 'index'])->name('warehouse.tasks.index');
    Route::get('/warehouse/warehouses/{warehouseId}/tasks/{taskId}', [WarehouseTaskController::class, 'show'])->name('warehouse.tasks.show');
    Route::post('/warehouse/warehouses/{warehouseId}/tasks/{taskId}/status', [WarehouseTaskController::class, 'updateStatus'])->name('warehouse.tasks.status');
    Route::get('/warehouse/materials/autocomplete', [WarehouseController::class, 'materialAutocomplete'])->name('warehouse.materials.autocomplete');
    Route::post('/warehouse/operations/receipt', [WarehouseController::class, 'receipt'])->name('warehouse.operations.receipt');
    Route::post('/warehouse/operations/transfer', [WarehouseController::class, 'transfer'])->name('warehouse.operations.transfer');
    Route::post('/warehouse/scan/resolve', [WarehouseScanController::class, 'resolve'])->name('warehouse.scan.resolve');

    Route::get('/warehouse/balances/{warehouseId}/{materialId}/photos', [WarehouseController::class, 'balancePhotos'])->name('warehouse.balances.photos.index');
    Route::post('/warehouse/balances/{warehouseId}/{materialId}/photos', [WarehouseController::class, 'uploadBalancePhotos'])->name('warehouse.balances.photos.store');
    Route::delete('/warehouse/balances/{warehouseId}/{materialId}/photos/{fileId}', [WarehouseController::class, 'deleteBalancePhoto'])->name('warehouse.balances.photos.destroy');

    Route::get('/warehouse/movements/{movementId}/photos', [WarehouseController::class, 'movementPhotos'])->name('warehouse.movements.photos.index');
    Route::post('/warehouse/movements/{movementId}/photos', [WarehouseController::class, 'uploadMovementPhotos'])->name('warehouse.movements.photos.store');
    Route::delete('/warehouse/movements/{movementId}/photos/{fileId}', [WarehouseController::class, 'deleteMovementPhoto'])->name('warehouse.movements.photos.destroy');
});
