<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\CustomReportController;
use App\Http\Controllers\Api\V1\Admin\CustomReportBuilderController;
use App\Http\Controllers\Api\V1\Admin\CustomReportScheduleController;

Route::prefix('custom-reports')->name('custom_reports.')->middleware(['module.access:reports'])->group(function () {
    
    Route::prefix('builder')->name('builder.')->group(function () {
        Route::get('/data-sources', [CustomReportBuilderController::class, 'getDataSources'])
            ->name('data_sources');
        
        Route::get('/data-sources/{dataSource}/fields', [CustomReportBuilderController::class, 'getDataSourceFields'])
            ->name('data_source_fields');
        
        Route::get('/data-sources/{dataSource}/relations', [CustomReportBuilderController::class, 'getDataSourceRelations'])
            ->name('data_source_relations');
        
        Route::post('/validate', [CustomReportBuilderController::class, 'validateConfig'])
            ->name('validate');
        
        Route::post('/preview', [CustomReportBuilderController::class, 'preview'])
            ->name('preview');
        
        Route::get('/operators', [CustomReportBuilderController::class, 'getOperators'])
            ->name('operators');
        
        Route::get('/aggregations', [CustomReportBuilderController::class, 'getAggregations'])
            ->name('aggregations');
        
        Route::get('/export-formats', [CustomReportBuilderController::class, 'getExportFormats'])
            ->name('export_formats');
        
        Route::get('/categories', [CustomReportBuilderController::class, 'getCategories'])
            ->name('categories');
    });

    Route::get('/', [CustomReportController::class, 'index'])->name('index');
    Route::post('/', [CustomReportController::class, 'store'])->name('store');
    Route::get('/{id}', [CustomReportController::class, 'show'])->name('show');
    Route::put('/{id}', [CustomReportController::class, 'update'])->name('update');
    Route::delete('/{id}', [CustomReportController::class, 'destroy'])->name('destroy');
    
    Route::post('/{id}/clone', [CustomReportController::class, 'clone'])->name('clone');
    Route::post('/{id}/favorite', [CustomReportController::class, 'toggleFavorite'])->name('toggle_favorite');
    Route::post('/{id}/share', [CustomReportController::class, 'updateSharing'])->name('update_sharing');
    
    Route::post('/{id}/execute', [CustomReportController::class, 'execute'])->name('execute');
    Route::get('/{id}/executions', [CustomReportController::class, 'executions'])->name('executions');
    
    Route::prefix('/{id}/schedules')->name('schedules.')->group(function () {
        Route::get('/', [CustomReportScheduleController::class, 'index'])->name('index');
        Route::post('/', [CustomReportScheduleController::class, 'store'])->name('store');
        Route::get('/{scheduleId}', [CustomReportScheduleController::class, 'show'])->name('show');
        Route::put('/{scheduleId}', [CustomReportScheduleController::class, 'update'])->name('update');
        Route::delete('/{scheduleId}', [CustomReportScheduleController::class, 'destroy'])->name('destroy');
        Route::post('/{scheduleId}/toggle', [CustomReportScheduleController::class, 'toggle'])->name('toggle');
        Route::post('/{scheduleId}/run-now', [CustomReportScheduleController::class, 'runNow'])->name('run_now');
    });
});

