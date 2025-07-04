<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\WorkTypeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\ContractorController;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Controllers\Api\V1\Admin\CostCategoryController;
use App\Http\Controllers\Api\V1\Admin\MeasurementUnitController;

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
// Route::get('/materials/measurement-units', [MaterialController::class, 'getMeasurementUnits'])->name('materials.measurementUnits');

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

// Маршрут для скачивания шаблона импорта материалов
Route::get('/materials/import-template', [MaterialController::class, 'downloadImportTemplate'])->name('materials.import-template');

// Поиск материалов (alias для index с параметрами q, limit)
Route::get('/materials/search', [MaterialController::class, 'index'])->name('materials.search');

Route::apiResource('materials', MaterialController::class);

Route::apiResource('suppliers', SupplierController::class);
Route::apiResource('contractors', ContractorController::class);
Route::apiResource('contracts', ContractController::class);

// Маршруты для единиц измерения
Route::get('/measurement-units/material-units', [MeasurementUnitController::class, 'getMaterialUnits'])->name('measurement-units.material-units');
Route::apiResource('measurement-units', MeasurementUnitController::class);

// Группа для видов работ и связанных с ними материалов
Route::apiresource('work-types', WorkTypeController::class)->except([]); // Оставляем все стандартные CRUD для WorkType
Route::prefix('work-types/{work_type}')->name('work-types.')->group(function () {
    // Подключаем маршруты для управления материалами, привязанными к виду работ
    if (file_exists(__DIR__ . '/work_type_materials.php')) {
        require __DIR__ . '/work_type_materials.php';
    }
});

// Маршруты для категорий затрат
Route::post('/cost-categories/import', [CostCategoryController::class, 'import'])->name('cost-categories.import');
Route::apiResource('cost-categories', CostCategoryController::class); 