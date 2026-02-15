<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\TimeTrackingController;

/*
|--------------------------------------------------------------------------
| Admin Time Tracking API Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления записями времени в админ-панели
|
*/

Route::prefix('time-tracking')->name('timeTracking.')->group(function () {
    
    // Статистика и отчеты (должны быть ВЫШЕ динамических маршрутов /{id})
    Route::get('/statistics', [TimeTrackingController::class, 'statistics'])->name('statistics');
    Route::get('/calendar', [TimeTrackingController::class, 'calendar'])->name('calendar');
    Route::get('/reports', [TimeTrackingController::class, 'report'])->name('report');
    Route::get('/export', [TimeTrackingController::class, 'export'])->name('export');
    Route::get('/workers', [TimeTrackingController::class, 'workers'])->name('workers');

    // Основные CRUD операции
    Route::get('/', [TimeTrackingController::class, 'index'])->name('index');
    Route::post('/', [TimeTrackingController::class, 'store'])->name('store');
    
    // Динамические маршруты (IDS)
    Route::get('/{id}', [TimeTrackingController::class, 'show'])->name('show');
    Route::put('/{id}', [TimeTrackingController::class, 'update'])->name('update');
    Route::delete('/{id}', [TimeTrackingController::class, 'destroy'])->name('destroy');
    
    // Управление статусами записей
    Route::post('/{id}/approve', [TimeTrackingController::class, 'approve'])->name('approve');
    Route::post('/{id}/reject', [TimeTrackingController::class, 'reject'])->name('reject');
    Route::post('/{id}/submit', [TimeTrackingController::class, 'submit'])->name('submit');
    
});