<?php

use App\Http\Controllers\Api\V1\Admin\TimeTrackingController;
use Illuminate\Support\Facades\Route;

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

    Route::post('/import/preview', [TimeTrackingController::class, 'importPreview'])->middleware('authorize:time_tracking.create')->name('importPreview');
    Route::post('/import/commit', [TimeTrackingController::class, 'importCommit'])->middleware('authorize:time_tracking.create')->name('importCommit');

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
