<?php

use App\Http\Controllers\Api\V1\Admin\ActReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('act-reports')->name('act-reports.')->group(function () {
    Route::get('/', [ActReportController::class, 'index'])->name('index');
    Route::post('/', [ActReportController::class, 'store'])->name('store');
    Route::get('/{actReport}', [ActReportController::class, 'show'])->name('show');
    Route::get('/{actReport}/download', [ActReportController::class, 'download'])->name('download');
    Route::post('/{actReport}/regenerate', [ActReportController::class, 'regenerate'])->name('regenerate');
    Route::delete('/{actReport}', [ActReportController::class, 'destroy'])->name('destroy');
}); 