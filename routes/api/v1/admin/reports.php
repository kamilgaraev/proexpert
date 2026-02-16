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
    
    // ЕДИНЫЙ МОДУЛЬ ОТЧЕТОВ (модуль reports)
    Route::middleware(['module.access:reports'])->group(function () {
        // Проверка доступности
        Route::get('check-availability', [ReportController::class, 'checkReportsAvailability'])->name('checkAvailability');
        
        Route::get('material-usage', [ReportController::class, 'materialUsageReport'])->name('material_usage');
        Route::get('work-completion', [ReportController::class, 'workCompletionReport'])->name('work_completion');
        Route::get('project-status-summary', [ReportController::class, 'projectStatusSummaryReport'])->name('project_status_summary');
        
        Route::get('contract-payments', [ReportController::class, 'contractPaymentsReport'])->name('contract_payments');
        Route::get('contractor-settlements', [ReportController::class, 'contractorSettlementsReport'])->name('contractor_settlements');
        Route::get('warehouse-stock', [ReportController::class, 'warehouseStockReport'])->name('warehouse_stock');
        Route::get('material-movements', [ReportController::class, 'materialMovementsReport'])->name('material_movements');
        Route::get('time-tracking', [ReportController::class, 'timeTrackingReport'])->name('time_tracking');
        Route::get('project-profitability', [ReportController::class, 'projectProfitabilityReport'])->name('project_profitability');
        Route::get('project-timelines', [ReportController::class, 'projectTimelinesReport'])->name('project_timelines');
        
        Route::get('contractor-summary', [ContractorReportController::class, 'contractorSummaryReport'])->name('contractor_summary');
        Route::get('contractor-detail/{contractorId}', [ContractorReportController::class, 'contractorDetailReport'])->name('contractor_detail');

        Route::get('foreman-activity', [ReportController::class, 'foremanActivityReport'])->name('foreman_activity');
        Route::get('official-material-usage', [ReportController::class, 'officialMaterialUsageReport'])->name('official_material_usage');
    });
});