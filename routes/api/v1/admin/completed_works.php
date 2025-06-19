<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\CompletedWorkController;

Route::apiResource('completed-works', CompletedWorkController::class);

Route::group(['prefix' => 'completed-works'], function () {
    Route::post('{completed_work}/sync-materials', [CompletedWorkController::class, 'syncMaterials'])
        ->name('completed-works.sync-materials');
    
    Route::get('work-type-material-defaults', [CompletedWorkController::class, 'getWorkTypeMaterialDefaults'])
        ->name('completed-works.work-type-material-defaults');
}); 