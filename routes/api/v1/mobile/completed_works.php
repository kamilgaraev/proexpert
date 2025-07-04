<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\CompletedWorkController;

// Маршруты управления выполненными работами в мобильном приложении
// Предполагается, что группа mobile уже защищена middleware 'auth:api_mobile', 'organization.context', 'can:access-mobile-app'

Route::apiResource('completed-works', CompletedWorkController::class)->except(['destroy']);

Route::group(['prefix' => 'completed-works'], function () {
    // Массовая синхронизация материалов
    Route::post('{completed_work}/sync-materials', [CompletedWorkController::class, 'syncMaterials'])
        ->name('completed-works.sync-materials');

    // Получение дефолтных материалов для типа работ
    Route::get('work-type-material-defaults', [CompletedWorkController::class, 'getWorkTypeMaterialDefaults'])
        ->name('completed-works.work-type-material-defaults');
}); 