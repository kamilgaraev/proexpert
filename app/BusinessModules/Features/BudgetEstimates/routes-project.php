<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Controllers\Api\V1\Admin\EstimateProgressController;
use App\Http\Controllers\Api\V1\Admin\EstimateContractController;
use App\Http\Controllers\Api\V1\Admin\EstimateExportController;
use App\Http\Controllers\Api\V1\Admin\EstimatePaymentController;

/*
|--------------------------------------------------------------------------
| Budget Estimates - Project Routes
|--------------------------------------------------------------------------
|
| Маршруты для работы со сметами В КОНТЕКСТЕ ПРОЕКТА
| Префикс: api/v1/admin/projects/{project}/estimates
|
*/

Route::middleware(['api', 'auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin', 'project.context', 'budget-estimates.active'])
    ->prefix('api/v1/admin/projects/{project}')
    ->name('admin.projects.estimates.')
    ->group(function () {
        
        Route::prefix('estimates')->group(function () {
            // Импорт смет
            Route::prefix('import')->name('import.')->group(function () {
                Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
                Route::post('/detect-type', [EstimateImportController::class, 'detectType'])->name('detect_type');
                Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
                Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
                Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
                Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
                Route::get('/status/{jobId?}', [EstimateImportController::class, 'status'])->name('status');
                Route::get('/history', [EstimateImportController::class, 'history'])->name('history');
                Route::post('/staging', [EstimateImportController::class, 'staging'])->name('staging');
                Route::post('/voice-command', [EstimateImportController::class, 'voiceCommand'])->name('voice_command');
            });

            // CRUD операции над сметами
            Route::get('/', [EstimateController::class, 'index'])->name('index');
            Route::post('/', [EstimateController::class, 'store'])->name('store');
            Route::get('/{estimate}', [EstimateController::class, 'show'])->name('show');
            Route::put('/{estimate}', [EstimateController::class, 'update'])->name('update');
            Route::delete('/{estimate}', [EstimateController::class, 'destroy'])->name('destroy');
            
            // Изменение статуса сметы
            Route::put('/{estimate}/status', [EstimateController::class, 'updateStatus'])->name('status.update');
            
            // Дополнительные операции
            Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('duplicate');
            Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate'])->name('recalculate');
            Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard'])->name('dashboard');
            Route::get('/{estimate}/structure', [EstimateController::class, 'structure'])->name('structure');

            Route::post('/{estimate}/what-if', [EstimateVersionController::class, 'whatIf'])->name('what_if');
            Route::post('/{estimate}/schedule', [EstimateVersionController::class, 'schedule'])->name('schedule');
            
            // Разделы сметы
            Route::prefix('{estimate}/sections')->name('sections.')->group(function () {
                Route::get('/', [EstimateSectionController::class, 'index'])->name('index');
                Route::post('/', [EstimateSectionController::class, 'store'])->name('store');
                
                // Массовое изменение порядка разделов (для drag-and-drop)
                Route::post('/reorder', [EstimateSectionController::class, 'reorder'])->name('reorder');
                
                // Пересчет нумерации разделов
                Route::post('/recalculate-numbers', [EstimateSectionController::class, 'recalculateNumbers'])->name('recalculate_numbers');
                
                // Валидация нумерации
                Route::get('/validate-numbering', [EstimateSectionController::class, 'validateNumbering'])->name('validate_numbering');
            });
            
            // Позиции сметы
            Route::prefix('{estimate}/items')->name('items.')->group(function () {
                Route::get('/', [EstimateItemController::class, 'index'])->name('index');
                Route::post('/', [EstimateItemController::class, 'store'])->name('store');
                Route::post('/bulk', [EstimateItemController::class, 'bulkStore'])->name('bulk_store');
                
                // Массовое изменение порядка позиций (для drag-and-drop)
                Route::post('/reorder', [EstimateItemController::class, 'reorder'])->name('reorder');
                
                // Пересчет нумерации позиций
                Route::post('/recalculate-numbers', [EstimateItemController::class, 'recalculateNumbers'])->name('recalculate_numbers');
            });
            
            // Прогресс выполнения сметы
            Route::prefix('{estimate}/progress')->name('progress.')->group(function () {
                Route::get('/actual-vs-planned', [EstimateProgressController::class, 'getActualVsPlanned'])->name('actual_vs_planned');
                Route::get('/completion-stats', [EstimateProgressController::class, 'getCompletionStats'])->name('completion_stats');
                Route::get('/items/{item}/journal-entries', [EstimateProgressController::class, 'getItemJournalEntries'])->name('item_journal_entries');
            });
            
            // Интеграция с договорами
            Route::prefix('{estimate}/contract')->name('contract.')->group(function () {
                Route::put('/', [EstimateContractController::class, 'linkContract'])->name('link');
                Route::delete('/', [EstimateContractController::class, 'unlinkContract'])->name('unlink');
                Route::get('/validation', [EstimateContractController::class, 'validateContractAmount'])->name('validation');
            });
            
            // Экспорт смет
            Route::prefix('{estimate}/export')->name('export.')->group(function () {
                // Экспорт сметы в форматах Prohelper
                Route::get('/excel', [EstimateExportController::class, 'exportEstimateToExcel'])->name('excel');
                Route::get('/pdf', [EstimateExportController::class, 'exportEstimateToPdf'])->name('pdf');
                
                // Экспорт официальных форм
                Route::post('/ks2', [EstimateExportController::class, 'exportKS2'])->name('ks2');
                Route::post('/ks3', [EstimateExportController::class, 'exportKS3'])->name('ks3');
                Route::post('/summary', [EstimateExportController::class, 'exportSummary'])->name('summary');
            });
            
            // Платежи по смете
            Route::get('/{estimate}/payments', [EstimatePaymentController::class, 'getPayments'])->name('payments');
        });
        
        // Интеграция с договорами (на уровне проекта)
        Route::prefix('contracts/{contract}/estimates')->name('contracts.estimates.')->group(function () {
            Route::post('/', [EstimateContractController::class, 'createFromContract'])->name('create');
            Route::get('/', [EstimateContractController::class, 'getEstimatesByContract'])->name('index');
        });
        
        // Экспорт журнала с фильтром по смете
        Route::prefix('construction-journal/{journal}/export')->name('construction_journal.export.')->group(function () {
            Route::post('/ks6', [EstimateExportController::class, 'exportKS6'])->name('ks6');
            Route::post('/extended-report', [EstimateExportController::class, 'exportExtendedReport'])->name('extended');
        });
    });

