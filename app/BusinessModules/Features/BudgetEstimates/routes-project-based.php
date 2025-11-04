<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;

/*
|--------------------------------------------------------------------------
| Budget Estimates Module Routes (Project-Based)
|--------------------------------------------------------------------------
|
| Эти маршруты загружаются внутри контекста проекта:
| Префикс: /api/v1/admin/projects/{project}
| Middleware: auth:api_admin, auth.jwt:api_admin, organization.context, 
|             authorize:admin.access, interface:admin, project.context
|
*/

Route::middleware(['budget-estimates.active'])
    ->prefix('estimates')
    ->group(function () {
        Route::get('/', [EstimateController::class, 'index']);
        Route::post('/', [EstimateController::class, 'store']);
        Route::get('/{estimate}', [EstimateController::class, 'show']);
        Route::put('/{estimate}', [EstimateController::class, 'update']);
        Route::delete('/{estimate}', [EstimateController::class, 'destroy']);
        
        Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate']);
        Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate']);
        Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard']);
        Route::get('/{estimate}/structure', [EstimateController::class, 'structure']);
        
        Route::prefix('{estimate}/sections')->group(function () {
            Route::get('/', [EstimateSectionController::class, 'index']);
            Route::post('/', [EstimateSectionController::class, 'store']);
        });
        
        Route::prefix('{estimate}/items')->group(function () {
            Route::get('/', [EstimateItemController::class, 'index']);
            Route::post('/', [EstimateItemController::class, 'store']);
            Route::post('/bulk', [EstimateItemController::class, 'bulkStore']);
        });
        
        Route::prefix('import')->name('estimates.import.')->group(function () {
            Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
            Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
            Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
            Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
            Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
            Route::get('/status/{jobId}', [EstimateImportController::class, 'status'])->name('status');
            Route::get('/history', [EstimateImportController::class, 'history'])->name('history');
        });
    });

