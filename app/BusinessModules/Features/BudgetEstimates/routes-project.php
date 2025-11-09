<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;

/*
|--------------------------------------------------------------------------
| Budget Estimates - Project Routes
|--------------------------------------------------------------------------
|
| Маршруты для работы со сметами В КОНТЕКСТЕ ПРОЕКТА
| Префикс: api/v1/admin/projects/{project}/estimates
|
*/

Route::middleware(['api', 'auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin', 'project.context', 'budget-estimates.active'])
    ->prefix('api/v1/admin/projects/{project}')
    ->name('admin.projects.estimates.')
    ->group(function () {
        
        Route::prefix('estimates')->group(function () {
            // CRUD операции над сметами
            Route::get('/', [EstimateController::class, 'index'])->name('index');
            Route::post('/', [EstimateController::class, 'store'])->name('store');
            Route::get('/{estimate}', [EstimateController::class, 'show'])->name('show');
            Route::put('/{estimate}', [EstimateController::class, 'update'])->name('update');
            Route::delete('/{estimate}', [EstimateController::class, 'destroy'])->name('destroy');
            
            // Изменение статуса сметы
            Route::put('/{estimate}/status', [EstimateController::class, 'updateStatus'])->name('status.update');
            
            // Дополнительные операции
            Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('duplicate');
            Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate'])->name('recalculate');
            Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard'])->name('dashboard');
            Route::get('/{estimate}/structure', [EstimateController::class, 'structure'])->name('structure');
            
            // Разделы сметы
            Route::prefix('{estimate}/sections')->name('sections.')->group(function () {
                Route::get('/', [EstimateSectionController::class, 'index'])->name('index');
                Route::post('/', [EstimateSectionController::class, 'store'])->name('store');
                
                // Массовое изменение порядка разделов (для drag-and-drop)
                Route::post('/reorder', [EstimateSectionController::class, 'reorder'])->name('reorder');
                
                // Пересчет нумерации разделов
                Route::post('/recalculate-numbers', [EstimateSectionController::class, 'recalculateNumbers'])->name('recalculate_numbers');
                
                // Валидация нумерации
                Route::get('/validate-numbering', [EstimateSectionController::class, 'validateNumbering'])->name('validate_numbering');
            });
            
            // Позиции сметы
            Route::prefix('{estimate}/items')->name('items.')->group(function () {
                Route::get('/', [EstimateItemController::class, 'index'])->name('index');
                Route::post('/', [EstimateItemController::class, 'store'])->name('store');
                Route::post('/bulk', [EstimateItemController::class, 'bulkStore'])->name('bulk_store');
                
                // Массовое изменение порядка позиций (для drag-and-drop)
                Route::post('/reorder', [EstimateItemController::class, 'reorder'])->name('reorder');
                
                // Пересчет нумерации позиций
                Route::post('/recalculate-numbers', [EstimateItemController::class, 'recalculateNumbers'])->name('recalculate_numbers');
            });
            
            // Импорт смет
            Route::prefix('import')->name('import.')->group(function () {
                Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
                Route::post('/detect-type', [EstimateImportController::class, 'detectType'])->name('detect_type'); // Определение типа сметы
                Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
                Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
                Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
                Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
                Route::get('/status/{jobId}', [EstimateImportController::class, 'status'])->name('status');
                Route::get('/history', [EstimateImportController::class, 'history'])->name('history');
            });
        });
    });

