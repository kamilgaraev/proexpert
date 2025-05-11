<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\WorkTypeController;
use App\Http\Controllers\Api\V1\Mobile\MaterialController;
use App\Http\Controllers\Api\V1\Mobile\SupplierController;
// Добавьте сюда другие контроллеры для мобильных справочников по мере необходимости

Route::middleware(['auth:api_mobile', 'can:access-mobile-app'])->group(function () {
    Route::get('work-types', [WorkTypeController::class, 'index'])->name('mobile.work-types.index');
    Route::get('materials', [MaterialController::class, 'index'])->name('mobile.materials.index');
    Route::get('suppliers', [SupplierController::class, 'index'])->name('mobile.suppliers.index');
    // Маршруты для других справочников (материалы, поставщики) будут здесь
}); 