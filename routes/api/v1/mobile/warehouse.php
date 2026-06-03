<?php

use App\Http\Controllers\Api\V1\Mobile\WarehouseScanController;
use App\Http\Controllers\Api\V1\Mobile\WarehouseController;
use App\Http\Controllers\Api\V1\Mobile\WarehouseTaskController;
use App\Http\Controllers\Api\V1\Mobile\ProjectMaterialDeliveryController;
use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseCustodyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/warehouse', [WarehouseController::class, 'index'])->name('warehouse.index');
    Route::get('/warehouse/warehouses/{warehouseId}/balances', [WarehouseController::class, 'balances'])->name('warehouse.balances');
    Route::get('/warehouse/warehouses/{warehouseId}/tasks', [WarehouseTaskController::class, 'index'])->name('warehouse.tasks.index');
    Route::get('/warehouse/warehouses/{warehouseId}/tasks/{taskId}', [WarehouseTaskController::class, 'show'])->name('warehouse.tasks.show');
    Route::post('/warehouse/warehouses/{warehouseId}/tasks/{taskId}/status', [WarehouseTaskController::class, 'updateStatus'])->name('warehouse.tasks.status');
    Route::get('/warehouse/materials/autocomplete', [WarehouseController::class, 'materialAutocomplete'])->name('warehouse.materials.autocomplete');
    Route::get('/warehouse/project-material-deliveries', [ProjectMaterialDeliveryController::class, 'index'])->name('warehouse.project-material-deliveries.index');
    Route::get('/warehouse/project-material-deliveries/project-stock', [ProjectMaterialDeliveryController::class, 'projectStock'])->name('warehouse.project-material-deliveries.project-stock');
    Route::get('/warehouse/project-material-deliveries/{deliveryId}', [ProjectMaterialDeliveryController::class, 'show'])->name('warehouse.project-material-deliveries.show');
    Route::post('/warehouse/project-material-deliveries/{deliveryId}/receive', [ProjectMaterialDeliveryController::class, 'receive'])->name('warehouse.project-material-deliveries.receive');
    Route::get('/warehouse/custody/balances', [WarehouseCustodyController::class, 'balances'])->name('warehouse.custody.balances');
    Route::post('/warehouse/custody/issue', [WarehouseCustodyController::class, 'issue'])->name('warehouse.custody.issue');
    Route::post('/warehouse/custody/return', [WarehouseCustodyController::class, 'returnToProject'])->name('warehouse.custody.return');
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
