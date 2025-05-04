<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\LogController;

/*
|--------------------------------------------------------------------------
| Mobile API Log Routes
|--------------------------------------------------------------------------
|
| Маршруты для логирования действий прораба в мобильном приложении.
|
*/

Route::middleware(['auth:api_mobile', 'can:access-mobile-app'])->group(function () {
    // Логирование расхода материалов
    Route::post('logs/material-usage', [LogController::class, 'storeMaterialUsage'])
        ->name('mobile.logs.material.store');

    // Логирование выполнения работ
    Route::post('logs/work-completion', [LogController::class, 'storeWorkCompletion'])
        ->name('mobile.logs.work.store');
}); 