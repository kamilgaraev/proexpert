<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ActReportsController;

// Маршруты для управления отчетами по актам
Route::prefix('act-reports')->group(function () {
    // Получить все акты организации с фильтрацией
    Route::get('/', [ActReportsController::class, 'index'])
        ->name('act-reports.index');
    
    // Получить детали конкретного акта
    Route::get('{act}', [ActReportsController::class, 'show'])
        ->name('act-reports.show');
    
    // Обновить основную информацию акта
    Route::put('{act}', [ActReportsController::class, 'update'])
        ->name('act-reports.update');
    
    // Получить доступные работы для включения в акт
    Route::get('{act}/available-works', [ActReportsController::class, 'getAvailableWorks'])
        ->name('act-reports.available-works');
    
    // Обновить состав работ в акте
    Route::put('{act}/works', [ActReportsController::class, 'updateWorks'])
        ->name('act-reports.update-works');
    
    // Экспорт акта в PDF
    Route::get('{act}/export/pdf', [ActReportsController::class, 'exportPdf'])
        ->name('act-reports.export.pdf');
    
    // Экспорт акта в Excel
    Route::get('{act}/export/excel', [ActReportsController::class, 'exportExcel'])
        ->name('act-reports.export.excel');
    
    // Массовый экспорт актов в Excel
    Route::post('bulk-export/excel', [ActReportsController::class, 'bulkExportExcel'])
        ->name('act-reports.bulk-export.excel');
}); 