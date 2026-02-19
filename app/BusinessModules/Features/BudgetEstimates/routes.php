<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\Http\Controllers\Api\V1\Admin\EstimateTemplateController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\BudgetEstimatesSettingsController;

/*
|--------------------------------------------------------------------------
| Budget Estimates Module Routes (non-project)
|--------------------------------------------------------------------------
|
| Маршруты модуля, не требующие контекста проекта
| (настройки модуля, операции с разделами/позициями, версии, шаблоны)
|
| ПРИМЕЧАНИЕ: Маршруты в контексте проекта находятся в:
| app/BusinessModules/Features/BudgetEstimates/routes-project.php
|
*/

// ============================================
// МАРШРУТЫ БЕЗ КОНТЕКСТА ПРОЕКТА
// ============================================
Route::prefix('api/v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:api_admin',
        'auth.jwt:api_admin',
        'organization.context',
        'budget-estimates.active'
    ])
    ->group(function () {
        
        // ============================================
        // ОПЕРАЦИИ НАД РАЗДЕЛАМИ
        // ============================================
        Route::prefix('estimate-sections')->name('estimate_sections.')->group(function () {
            Route::get('/{section}', [EstimateSectionController::class, 'show'])->name('show');
            Route::put('/{section}', [EstimateSectionController::class, 'update'])->name('update');
            Route::delete('/{section}', [EstimateSectionController::class, 'destroy'])->name('destroy');
            Route::post('/{section}/move', [EstimateSectionController::class, 'move'])->name('move');
        });

        // ============================================
        // ОПЕРАЦИИ НАД ПОЗИЦИЯМИ
        // ============================================
        Route::prefix('estimate-items')->name('estimate_items.')->group(function () {
            Route::get('/{item}', [EstimateItemController::class, 'show'])->name('show');
            Route::put('/{item}', [EstimateItemController::class, 'update'])->name('update');
            Route::delete('/{item}', [EstimateItemController::class, 'destroy'])->name('destroy');
            Route::post('/{item}/move', [EstimateItemController::class, 'move'])->name('move');
        });

        // ============================================
        // ВЕРСИОНИРОВАНИЕ
        // ============================================
        Route::prefix('estimate-versions')->name('estimate_versions.')->group(function () {
            Route::get('/{estimate}', [EstimateVersionController::class, 'index'])->name('index');
            Route::post('/{estimate}', [EstimateVersionController::class, 'store'])->name('store');
            Route::post('/compare', [EstimateVersionController::class, 'compare'])->name('compare');
            Route::post('/{version}/rollback', [EstimateVersionController::class, 'rollback'])->name('rollback');
            Route::post('/snapshot-diff', [EstimateVersionController::class, 'snapshotDiff'])->name('snapshot_diff');
        });

        // ============================================
        // СЛОЙ ПАМЯТИ ИМПОРТА
        // ============================================
        Route::prefix('import-memories')->name('import_memories.')->group(function () {
            Route::get('/', [EstimateVersionController::class, 'memoryList'])->name('index');
            Route::post('/feedback', [EstimateVersionController::class, 'memoryFeedback'])->name('feedback');
        });

        // ============================================
        // ШАБЛОНЫ СМЕТ
        // ============================================
        Route::prefix('estimate-templates')->name('estimate_templates.')->group(function () {
            Route::get('/', [EstimateTemplateController::class, 'index'])->name('index');
            Route::post('/', [EstimateTemplateController::class, 'store'])->name('store');
            Route::get('/{template}', [EstimateTemplateController::class, 'show'])->name('show');
            Route::delete('/{template}', [EstimateTemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{template}/apply', [EstimateTemplateController::class, 'apply'])->name('apply');
            Route::post('/{template}/share', [EstimateTemplateController::class, 'share'])->name('share');
        });

        // ============================================
        // НАСТРОЙКИ МОДУЛЯ
        // ============================================
        Route::prefix('modules/budget-estimates')->name('modules.budget_estimates.')->group(function () {
            Route::get('/settings', [BudgetEstimatesSettingsController::class, 'show'])->name('settings.show');
            Route::put('/settings', [BudgetEstimatesSettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/reset', [BudgetEstimatesSettingsController::class, 'reset'])->name('settings.reset');
            Route::get('/info', [BudgetEstimatesSettingsController::class, 'info'])->name('info');
        });
    });

