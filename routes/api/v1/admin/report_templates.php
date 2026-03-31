<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportTemplateController;

Route::prefix('report-templates')->name('report-templates.')->group(function () {
    Route::get('/', [ReportTemplateController::class, 'index'])
        ->middleware('authorize:reports.view')
        ->name('index');
    Route::post('/', [ReportTemplateController::class, 'store'])
        ->middleware('authorize:report_templates.create')
        ->name('store');
    Route::get('/{templateId}', [ReportTemplateController::class, 'show'])
        ->middleware('authorize:reports.view')
        ->name('show');
    Route::match(['put', 'patch'], '/{templateId}', [ReportTemplateController::class, 'update'])
        ->middleware('authorize:report_templates.edit')
        ->name('update');
    Route::delete('/{templateId}', [ReportTemplateController::class, 'destroy'])
        ->middleware('authorize:report_templates.delete')
        ->name('destroy');
    Route::post('/{templateId}/set-default', [ReportTemplateController::class, 'setDefault'])
        ->middleware('authorize:report_templates.set_default')
        ->name('set-default');
});
