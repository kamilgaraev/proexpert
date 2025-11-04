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

Route::prefix('api/v1/admin/projects/{project}')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin', 'project.context', 'budget-estimates.active'])
    ->name('admin.projects.estimates.')
    ->group(function () {
        
        Route::prefix('estimates')->group(function () {
            // CRUD операции над сметами
            Route::get('/', [EstimateController::class, 'index'])->name('index');
            Route::post('/', [EstimateController::class, 'store'])->name('store');
            Route::get('/{estimate}', [EstimateController::class, 'show'])->name('show');
            Route::put('/{estimate}', [EstimateController::class, 'update'])->name('update');
            Route::delete('/{estimate}', [EstimateController::class, 'destroy'])->name('destroy');
            
            // Дополнительные операции
            Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('duplicate');
            Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate'])->name('recalculate');
            Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard'])->name('dashboard');
            Route::get('/{estimate}/structure', [EstimateController::class, 'structure'])->name('structure');
            
            // Разделы сметы
            Route::prefix('{estimate}/sections')->name('sections.')->group(function () {
                Route::get('/', [EstimateSectionController::class, 'index'])->name('index');
                Route::post('/', [EstimateSectionController::class, 'store'])->name('store');
            });
            
            // Позиции сметы
            Route::prefix('{estimate}/items')->name('items.')->group(function () {
                Route::get('/', [EstimateItemController::class, 'index'])->name('index');
                Route::post('/', [EstimateItemController::class, 'store'])->name('store');
                Route::post('/bulk', [EstimateItemController::class, 'bulkStore'])->name('bulk_store');
            });
            
            // Импорт смет
            Route::prefix('import')->name('import.')->group(function () {
                Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
                Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
                Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
                Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
                Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
                Route::get('/status/{jobId}', [EstimateImportController::class, 'status'])->name('status');
                Route::get('/history', [EstimateImportController::class, 'history'])->name('history');
            });
        });
    });

