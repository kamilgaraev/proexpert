<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\Http\Controllers\Api\V1\Admin\EstimateTemplateController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    
    Route::prefix('estimates')->group(function () {
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
    });
    
    Route::prefix('estimate-sections')->group(function () {
        Route::get('/{section}', [EstimateSectionController::class, 'show']);
        Route::put('/{section}', [EstimateSectionController::class, 'update']);
        Route::delete('/{section}', [EstimateSectionController::class, 'destroy']);
        Route::post('/{section}/move', [EstimateSectionController::class, 'move']);
    });
    
    Route::prefix('estimate-items')->group(function () {
        Route::get('/{item}', [EstimateItemController::class, 'show']);
        Route::put('/{item}', [EstimateItemController::class, 'update']);
        Route::delete('/{item}', [EstimateItemController::class, 'destroy']);
        Route::post('/{item}/move', [EstimateItemController::class, 'move']);
    });
    
    Route::prefix('estimate-versions')->group(function () {
        Route::get('/{estimate}', [EstimateVersionController::class, 'index']);
        Route::post('/{estimate}', [EstimateVersionController::class, 'store']);
        Route::post('/compare', [EstimateVersionController::class, 'compare']);
        Route::post('/{version}/rollback', [EstimateVersionController::class, 'rollback']);
    });
    
    Route::prefix('estimate-templates')->group(function () {
        Route::get('/', [EstimateTemplateController::class, 'index']);
        Route::post('/', [EstimateTemplateController::class, 'store']);
        Route::get('/{template}', [EstimateTemplateController::class, 'show']);
        Route::delete('/{template}', [EstimateTemplateController::class, 'destroy']);
        Route::post('/{template}/apply', [EstimateTemplateController::class, 'apply']);
        Route::post('/{template}/share', [EstimateTemplateController::class, 'share']);
    });
});

