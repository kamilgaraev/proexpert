<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\TimeTrackingController;

/*
|--------------------------------------------------------------------------
| Mobile Time Tracking API Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления записями времени в мобильном приложении
|
*/

Route::prefix('time-tracking')->name('timeTracking.')->group(function () {
    
    // Основные CRUD операции (только для собственных записей)
    Route::get('/', [TimeTrackingController::class, 'index'])->name('index');
    Route::post('/', [TimeTrackingController::class, 'store'])->name('store');
    Route::get('/{id}', [TimeTrackingController::class, 'show'])->name('show');
    Route::put('/{id}', [TimeTrackingController::class, 'update'])->name('update');
    Route::delete('/{id}', [TimeTrackingController::class, 'destroy'])->name('destroy');
    
    // Отправка на утверждение
    Route::post('/{id}/submit', [TimeTrackingController::class, 'submit'])->name('submit');
    
    // Статистика пользователя
    Route::get('/statistics/my', [TimeTrackingController::class, 'statistics'])->name('statistics');
    Route::get('/calendar/my', [TimeTrackingController::class, 'calendar'])->name('calendar');
    
    // Функции таймера
    Route::post('/timer/start', [TimeTrackingController::class, 'startTimer'])->name('timer.start');
    Route::post('/timer/{id}/stop', [TimeTrackingController::class, 'stopTimer'])->name('timer.stop');
    Route::get('/timer/active', [TimeTrackingController::class, 'activeTimer'])->name('timer.active');
    
});