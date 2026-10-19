<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportFileController;

// Группа уже защищена middleware в RouteServiceProvider
Route::prefix('report-files')->name('report_files.')->group(function () {
    Route::get('/', [ReportFileController::class, 'index'])->middleware('authorize:report_files.view')->name('index');
    Route::get('{id}/download-url', [ReportFileController::class, 'downloadUrl'])->middleware('authorize:report_files.view')->name('download_url');
    Route::delete('{id}', [ReportFileController::class, 'destroy'])->middleware('authorize:report_files.delete')->name('destroy');
    Route::patch('{id}', [ReportFileController::class, 'update'])->middleware('authorize:report_files.update')->name('update');
}); 
