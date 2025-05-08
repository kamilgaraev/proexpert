<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\WorkTypeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\CostCategoryController;

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

// Новые маршруты для работы с нормами списания материалов
Route::get('/materials/{id}/consumption-rates', [MaterialController::class, 'getConsumptionRates'])
    ->name('materials.consumption-rates.index');
Route::put('/materials/{id}/consumption-rates', [MaterialController::class, 'updateConsumptionRates'])
    ->name('materials.consumption-rates.update');

// Маршрут для проверки валидности материала для интеграции с СБИС/1С
Route::get('/materials/{id}/validate-for-accounting', [MaterialController::class, 'validateForAccounting'])
    ->name('materials.validate-for-accounting');

Route::apiResource('materials', MaterialController::class);
Route::apiResource('work-types', WorkTypeController::class);
Route::apiResource('suppliers', SupplierController::class);

// Маршруты для категорий затрат
Route::post('/cost-categories/import', [CostCategoryController::class, 'import'])->name('cost-categories.import');
Route::apiResource('cost-categories', CostCategoryController::class); 