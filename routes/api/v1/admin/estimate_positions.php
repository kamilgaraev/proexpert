<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimatePositionCatalogController;
use App\Http\Controllers\Api\V1\Admin\EstimatePositionCategoryController;
use App\Http\Controllers\Api\V1\Admin\EstimatePositionPriceHistoryController;
use App\Http\Controllers\Api\V1\Admin\EstimatePositionImportExportController;

/*
|--------------------------------------------------------------------------
| Estimate Position Catalog Routes
|--------------------------------------------------------------------------
|
| Маршруты для справочника позиций сметы
|
*/

// Справочник позиций сметы
Route::prefix('estimate-positions')->name('estimate-positions.')->group(function () {
    // Поиск позиций
    Route::get('/search', [EstimatePositionCatalogController::class, 'search'])->name('search');
    
    // Импорт/экспорт
    Route::get('/import/template', [EstimatePositionImportExportController::class, 'template'])->name('import.template');
    Route::post('/import', [EstimatePositionImportExportController::class, 'import'])->name('import');
    Route::post('/export/excel', [EstimatePositionImportExportController::class, 'exportExcel'])->name('export.excel');
    Route::post('/export/csv', [EstimatePositionImportExportController::class, 'exportCsv'])->name('export.csv');
    
    // История цен
    Route::get('/{id}/price-history', [EstimatePositionPriceHistoryController::class, 'show'])->name('price-history');
    Route::post('/price-history/compare', [EstimatePositionPriceHistoryController::class, 'compare'])->name('price-history.compare');
    
    // CRUD
    Route::get('/', [EstimatePositionCatalogController::class, 'index'])->name('index');
    Route::post('/', [EstimatePositionCatalogController::class, 'store'])->name('store');
    Route::get('/{id}', [EstimatePositionCatalogController::class, 'show'])->name('show');
    Route::put('/{id}', [EstimatePositionCatalogController::class, 'update'])->name('update');
    Route::delete('/{id}', [EstimatePositionCatalogController::class, 'destroy'])->name('destroy');
});

// Категории позиций
Route::prefix('estimate-position-categories')->name('estimate-position-categories.')->group(function () {
    // Дерево категорий
    Route::get('/tree', [EstimatePositionCategoryController::class, 'tree'])->name('tree');
    
    // Изменить порядок
    Route::post('/reorder', [EstimatePositionCategoryController::class, 'reorder'])->name('reorder');
    
    // CRUD
    Route::get('/', [EstimatePositionCategoryController::class, 'index'])->name('index');
    Route::post('/', [EstimatePositionCategoryController::class, 'store'])->name('store');
    Route::get('/{id}', [EstimatePositionCategoryController::class, 'show'])->name('show');
    Route::put('/{id}', [EstimatePositionCategoryController::class, 'update'])->name('update');
    Route::delete('/{id}', [EstimatePositionCategoryController::class, 'destroy'])->name('destroy');
});

