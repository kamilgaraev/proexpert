<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\WorkTypeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Catalog Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления справочниками (Материалы, Виды работ, Поставщики).
|
*/

// Группа уже защищена middleware в RouteServiceProvider

// Маршрут для получения единиц измерения должен быть определен ПЕРЕД apiResource для материалов
Route::get('/materials/measurement-units', [MaterialController::class, 'getMeasurementUnits'])->name('materials.measurementUnits');

// Маршруты для балансов материалов (если ID материала передается как параметр)
// Пример: /materials/{materialId}/balances
Route::get('/materials/{id}/balances', [MaterialController::class, 'getMaterialBalances'])->name('materials.balances');

// Маршрут для импорта материалов
Route::post('/materials/import', [MaterialController::class, 'importMaterials'])->name('materials.import');

Route::apiResource('materials', MaterialController::class);
Route::apiResource('work-types', WorkTypeController::class);
Route::apiResource('suppliers', SupplierController::class); 