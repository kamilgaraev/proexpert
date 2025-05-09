<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ReportTemplateController;

Route::apiResource('report-templates', ReportTemplateController::class);
Route::post('report-templates/{template_id}/set-default', [ReportTemplateController::class, 'setDefault'])->name('report-templates.set-default'); 