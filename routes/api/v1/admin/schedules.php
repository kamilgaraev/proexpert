<?php

use App\Http\Controllers\Api\V1\Schedule\ProjectScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('schedules')->name('schedules.')->group(function () {
    Route::get('/statistics', [ProjectScheduleController::class, 'statistics'])->name('statistics');
    Route::get('/overdue', [ProjectScheduleController::class, 'overdue'])->name('overdue');
    Route::get('/recent', [ProjectScheduleController::class, 'recent'])->name('recent');
    Route::get('/all-resource-conflicts', [ProjectScheduleController::class, 'allResourceConflicts'])->name('all-resource-conflicts');
});
