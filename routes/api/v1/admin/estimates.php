<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\Http\Controllers\Api\V1\Admin\EstimateTemplateController;

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


