<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ErrorTrackingController;

/*
|--------------------------------------------------------------------------
| Error Tracking API Routes
|--------------------------------------------------------------------------
|
| Маршруты для системы отслеживания ошибок
| Доступны для администраторов с JWT аутентификацией
|
*/

Route::prefix('error-tracking')
    ->name('error_tracking.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin'])
    ->group(function () {
        
        // Список ошибок
        Route::get('/', [ErrorTrackingController::class, 'index'])->name('index');
        
        // Детали ошибки
        Route::get('/{id}', [ErrorTrackingController::class, 'show'])->name('show');
        
        // Статистика
        Route::get('/stats/statistics', [ErrorTrackingController::class, 'statistics'])->name('statistics');
        
        // Топ ошибок
        Route::get('/stats/top', [ErrorTrackingController::class, 'top'])->name('top');
        
        // График для Grafana
        Route::get('/stats/timeseries', [ErrorTrackingController::class, 'timeseries'])->name('timeseries');
        
        // Обновить статус ошибки
        Route::patch('/{id}/status', [ErrorTrackingController::class, 'updateStatus'])->name('update_status');
    });

