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
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateNormativeController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\WorkVolumeStatementController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\WorkVolumeStatementImportController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\WorkVolumeCoverageController;

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
    ->where(['project' => '[0-9]+'])
    ->name('admin.projects.estimates.')
    ->group(function () {
        
        Route::prefix('estimates')->group(function () {
            Route::get('/finance', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'project'])
                ->middleware('authorize:budget-estimates.finance.view,project,project')->name('finance.project');
            Route::get('/finance/export', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'export'])
                ->middleware('authorize:budget-estimates.finance.view,project,project')->name('finance.project.export');
            Route::prefix('{estimate}/finance')->where(['estimate' => '[0-9]+'])->group(function () {
                Route::get('/', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'show'])->middleware('authorize:budget-estimates.finance.view,project,project');
                Route::get('/history', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'history'])->middleware('authorize:budget-estimates.finance.view,project,project');
                Route::get('/export', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'export'])->middleware('authorize:budget-estimates.finance.view,project,project');
                Route::get('/items/{item}', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'item'])->whereNumber('item')->middleware('authorize:budget-estimates.finance.view,project,project');
                Route::post('/preview', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'preview'])->middleware('authorize:budget-estimates.finance.edit,project,project');
                Route::put('/', [\App\BusinessModules\Features\BudgetEstimates\Http\Controllers\EstimateFinanceController::class, 'save'])->middleware('authorize:budget-estimates.finance.edit,project,project');
            });
            // Импорт смет
            Route::prefix('import')->name('import.')->group(function () {
                Route::get('/template', [EstimateImportController::class, 'downloadTemplate'])->name('template');
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

            Route::prefix('{estimate}/normatives')->name('normatives.')->group(function () {
                Route::get('/search', [EstimateNormativeController::class, 'search'])->name('search');
                Route::get('/{norm}', [EstimateNormativeController::class, 'show'])->name('show');
            });

            Route::post('/{estimate}/what-if', [EstimateVersionController::class, 'whatIfForProject'])->name('what_if');
            // Route::post('/{estimate}/schedule', [EstimateVersionController::class, 'schedule'])->name('schedule');
            
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
                Route::get('/{section}', [EstimateSectionController::class, 'showForProject'])->name('show');
                Route::put('/{section}', [EstimateSectionController::class, 'updateForProject'])->name('update');
                Route::delete('/{section}', [EstimateSectionController::class, 'destroyForProject'])->name('destroy');
                Route::post('/{section}/move', [EstimateSectionController::class, 'moveForProject'])->name('move');
            });
            
            // Позиции сметы
            Route::prefix('{estimate}/items')->name('items.')->group(function () {
                Route::get('/', [EstimateItemController::class, 'index'])->name('index');
                Route::post('/', [EstimateItemController::class, 'store'])->name('store');
                Route::post('/bulk', [EstimateItemController::class, 'bulkStore'])->name('bulk_store');
                Route::put('/bulk', [EstimateItemController::class, 'bulkUpdate'])->name('bulk_update');
                Route::post('/from-estimate-norms', [EstimateNormativeController::class, 'storeItems'])->name('from_estimate_norms');
                
                // Массовое изменение порядка позиций (для drag-and-drop)
                Route::post('/reorder', [EstimateItemController::class, 'reorder'])->name('reorder');
                
                // Пересчет нумерации позиций
                Route::post('/recalculate-numbers', [EstimateItemController::class, 'recalculateNumbers'])->name('recalculate_numbers');
                Route::get('/{item}', [EstimateItemController::class, 'showForProject'])->name('show');
                Route::put('/{item}', [EstimateItemController::class, 'updateForProject'])->name('update');
                Route::delete('/{item}', [EstimateItemController::class, 'destroyForProject'])->name('destroy');
                Route::post('/{item}/move', [EstimateItemController::class, 'moveForProject'])->name('move');
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
                Route::get('/coverage', [EstimateContractController::class, 'getCoverage'])->name('coverage');
            });
            
            // Экспорт смет
            Route::prefix('{estimate}/export')->name('export.')->group(function () {
                // Экспорт сметы в форматах МОСТ
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

        Route::prefix('work-volume-statements')->name('work_volume_statements.')->group(function () {
            Route::get('/unmapped-facts', [WorkVolumeStatementController::class, 'unmappedFacts'])
                ->middleware('authorize:budget-estimates.view,project,project')->name('unmapped_facts');
            Route::prefix('imports')->name('imports.')->group(function () {
                Route::get('/', [WorkVolumeStatementImportController::class, 'index'])
                    ->middleware('authorize:budget-estimates.view,project,project')->name('index');
                Route::post('/', [WorkVolumeStatementImportController::class, 'store'])
                    ->middleware('authorize:budget-estimates.edit,project,project')->name('store');
                Route::get('/{import}', [WorkVolumeStatementImportController::class, 'show'])
                    ->whereNumber('import')->middleware('authorize:budget-estimates.view,project,project')->name('show');
                Route::put('/{import}/preview', [WorkVolumeStatementImportController::class, 'save'])
                    ->whereNumber('import')->middleware('authorize:budget-estimates.edit,project,project')->name('save');
                Route::patch('/{import}/preview', [WorkVolumeStatementImportController::class, 'patch'])
                    ->whereNumber('import')->middleware('authorize:budget-estimates.edit,project,project')->name('patch');
                Route::post('/{import}/register', [WorkVolumeStatementImportController::class, 'register'])
                    ->whereNumber('import')->middleware('authorize:budget-estimates.edit,project,project')->name('register');
                Route::get('/{import}/source', [WorkVolumeStatementImportController::class, 'source'])
                    ->whereNumber('import')->middleware('authorize:budget-estimates.view,project,project')->name('source');
            });
            Route::get('/accepted-mappings/{actLine}', [WorkVolumeStatementController::class, 'acceptedMappingHistory'])
                ->whereNumber('actLine')->middleware('authorize:budget-estimates.view,project,project')->name('accepted_mappings.history');
            Route::put('/accepted-mappings/{actLine}', [WorkVolumeStatementController::class, 'mapAccepted'])
                ->whereNumber('actLine')->middleware('authorize:budget-estimates.approve,project,project')->name('accepted_mappings.replace');
            Route::post('/preview-import', [WorkVolumeStatementController::class, 'previewImport'])
                ->middleware('authorize:budget-estimates.edit,project,project')->name('preview_import');
            Route::get('/', [WorkVolumeStatementController::class, 'index'])
                ->middleware('authorize:budget-estimates.view,project,project')->name('index');
            Route::post('/', [WorkVolumeStatementController::class, 'store'])
                ->middleware('authorize:budget-estimates.edit,project,project')->name('store');
            Route::get('/{statement}', [WorkVolumeStatementController::class, 'show'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.view,project,project')->name('show');
            Route::get('/{statement}/compare/{otherStatement}', [WorkVolumeStatementController::class, 'compare'])
                ->whereNumber(['statement', 'otherStatement'])->middleware('authorize:budget-estimates.view,project,project')->name('compare');
            Route::post('/{statement}/revisions', [WorkVolumeStatementController::class, 'revision'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.edit,project,project')->name('revision');
            Route::post('/{statement}/approve', [WorkVolumeStatementController::class, 'approve'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.approve,project,project')->name('approve');
            Route::post('/{statement}/submit', [WorkVolumeStatementController::class, 'submitForReview'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.edit,project,project')->name('submit');
            Route::post('/{statement}/return', [WorkVolumeStatementController::class, 'returnForCorrection'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.approve,project,project')->name('return');
            Route::put('/{statement}/draft', [WorkVolumeStatementController::class, 'editDraft'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.edit,project,project')->name('draft.edit');
            Route::get('/{statement}/coverage', [WorkVolumeCoverageController::class, 'index'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.view,project,project')->name('coverage.index');
            Route::put('/{statement}/coverage', [WorkVolumeCoverageController::class, 'replace'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.edit,project,project')->name('coverage.replace');
            Route::get('/{statement}/coverage/source-reviews', [WorkVolumeCoverageController::class, 'sourceReviews'])
                ->whereNumber('statement')->middleware('authorize:budget-estimates.view,project,project')->name('coverage.source_reviews');
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
