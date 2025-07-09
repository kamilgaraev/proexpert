<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportFileController;

// Группа уже защищена middleware в RouteServiceProvider
Route::prefix('report-files')->name('report_files.')->group(function () {
    Route::get('/', [ReportFileController::class, 'index'])->name('index');
    Route::delete('{key}', [ReportFileController::class, 'destroy'])->name('destroy');
    Route::patch('{key}', [ReportFileController::class, 'update'])->name('update');
}); 