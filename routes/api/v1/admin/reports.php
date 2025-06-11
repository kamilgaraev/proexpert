<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Report Routes
|--------------------------------------------------------------------------
|
| Маршруты для генерации отчетов в административной панели.
|
*/

// Группа уже защищена middleware в RouteServiceProvider
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('material-usage', [ReportController::class, 'materialUsageReport'])->name('material_usage');
    Route::get('work-completion', [ReportController::class, 'workCompletionReport'])->name('work_completion');
    Route::get('foreman-activity', [ReportController::class, 'foremanActivityReport'])->name('foreman_activity');
    Route::get('project-status-summary', [ReportController::class, 'projectStatusSummaryReport'])->name('project_status_summary');
    Route::get('official-material-usage', [ReportController::class, 'officialMaterialUsageReport'])->name('official_material_usage');
}); 