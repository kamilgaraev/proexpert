<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ActReportsController;

// Маршруты для управления отчетами по актам
Route::prefix('act-reports')->group(function () {
    // Получить все акты организации с фильтрацией
    Route::get('/', [ActReportsController::class, 'index'])
        ->name('act-reports.index');
    
    // Получить список контрактов для создания актов
    Route::get('contracts', [ActReportsController::class, 'getContracts'])
        ->name('act-reports.contracts');

    Route::post('preview', [ActReportsController::class, 'preview'])
        ->name('act-reports.preview');

    Route::post('create-from-wizard', [ActReportsController::class, 'createFromWizard'])
        ->name('act-reports.create-from-wizard');
    
    // Создать новый акт
    Route::post('/', [ActReportsController::class, 'store'])
        ->name('act-reports.store');
    
    // Массовый экспорт актов в Excel (должен быть перед {act} маршрутами)
    Route::post('bulk-export/excel', [ActReportsController::class, 'bulkExportExcel'])
        ->name('act-reports.bulk-export.excel');
    
    // Получить доступные работы для включения в акт
    Route::get('{act}/available-works', [ActReportsController::class, 'getAvailableWorks'])
        ->name('act-reports.available-works');
    
    // Обновить состав работ в акте
    Route::put('{act}/works', [ActReportsController::class, 'updateWorks'])
        ->name('act-reports.update-works');
    
    // Экспорт акта в PDF
    Route::get('{act}/export/pdf', [ActReportsController::class, 'exportPdf'])
        ->name('act-reports.export.pdf');

    Route::post('{act}/submit', [ActReportsController::class, 'submit'])
        ->name('act-reports.submit');

    Route::post('{act}/approve', [ActReportsController::class, 'approve'])
        ->name('act-reports.approve');

    Route::post('{act}/reject', [ActReportsController::class, 'reject'])
        ->name('act-reports.reject');

    Route::get('{act}/export/ks3', [ActReportsController::class, 'exportKS3'])
        ->name('act-reports.export.ks3');

    Route::get('{act}/export/ks3/pdf', [ActReportsController::class, 'exportKS3Pdf'])
        ->name('act-reports.export.ks3.pdf');

    Route::post('{act}/signed-file', [ActReportsController::class, 'uploadSignedFile'])
        ->name('act-reports.signed-file.upload');
    
    // Скачивание сохраненного PDF акта
    Route::get('{act}/download-pdf/{file}', [ActReportsController::class, 'downloadPdf'])
        ->name('act-reports.download-pdf');
    
    // Экспорт акта в Excel
    Route::get('{act}/export/excel', [ActReportsController::class, 'exportExcel'])
        ->name('act-reports.export.excel');
    
    // Получить детали конкретного акта
    Route::get('{act}', [ActReportsController::class, 'show'])
        ->name('act-reports.show');
    
    // Обновить основную информацию акта
    Route::put('{act}', [ActReportsController::class, 'update'])
        ->name('act-reports.update');
    
    // Маршруты для работы с файлами актов
    Route::prefix('{act}/files')->group(function () {
        // Загрузить файл к акту
        Route::post('/', [ActReportsController::class, 'uploadFile'])
            ->name('act-reports.files.upload');
        
        // Получить список файлов акта
        Route::get('/', [ActReportsController::class, 'getFiles'])
            ->name('act-reports.files.index');
        
        // Скачать файл акта
        Route::get('{file}', [ActReportsController::class, 'downloadFile'])
            ->name('act-reports.files.download');
        
        // Скопировать файл в личное хранилище
        Route::post('{file}/copy-to-personal', [ActReportsController::class, 'copyToPersonalStorage'])
            ->name('act-reports.files.copy-to-personal');
        
        // Удалить файл
        Route::delete('{file}', [ActReportsController::class, 'deleteFile'])
            ->name('act-reports.files.delete');
    });
}); 
