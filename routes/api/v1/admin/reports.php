<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ContractorReportController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Report Routes
|--------------------------------------------------------------------------
|
| Маршруты для генерации отчетов в административной панели.
| Разделены на базовые (бесплатные) и продвинутые (платные) модули.
|
*/

Route::prefix('reports')->name('reports.')->group(function () {
    
    // Проверка доступности модулей отчетов
    Route::get('/check-basic-availability', [ReportController::class, 'checkBasicReportsAvailability'])
        ->name('checkBasicAvailability');
    Route::get('/check-advanced-availability', [ReportController::class, 'checkAdvancedReportsAvailability'])
        ->name('checkAdvancedAvailability');
    
    // БАЗОВЫЕ ОТЧЕТЫ (модуль basic_reports - бесплатный)
    Route::middleware(['module.access:basic_reports'])->group(function () {
        Route::get('material-usage', [ReportController::class, 'materialUsageReport'])->name('material_usage');
        Route::get('work-completion', [ReportController::class, 'workCompletionReport'])->name('work_completion');
        Route::get('project-status-summary', [ReportController::class, 'projectStatusSummaryReport'])->name('project_status_summary');
    });
    
    // ПРОДВИНУТЫЕ ОТЧЕТЫ (модуль advanced_reports - платный)
    Route::middleware(['module.access:advanced_reports'])->group(function () {
        Route::get('foreman-activity', [ReportController::class, 'foremanActivityReport'])->name('foreman_activity');
        Route::get('official-material-usage', [ReportController::class, 'officialMaterialUsageReport'])->name('official_material_usage');
        
        // Отчеты по подрядчикам (тоже продвинутые)
        Route::get('contractor-summary', [ContractorReportController::class, 'contractorSummaryReport'])->name('contractor_summary');
        Route::get('contractor-detail/{contractorId}', [ContractorReportController::class, 'contractorDetailReport'])->name('contractor_detail');
    });
});