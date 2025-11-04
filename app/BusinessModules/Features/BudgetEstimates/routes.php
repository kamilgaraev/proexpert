<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\Http\Controllers\Api\V1\Admin\EstimateTemplateController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\BudgetEstimatesSettingsController;

/*
|--------------------------------------------------------------------------
| Budget Estimates Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля "Сметное дело"
| Все маршруты защищены middleware: auth:api_admin, organization.context, budget-estimates.active
|
*/

Route::prefix('api/v1/admin/projects/{project}')
    ->name('admin.projects.estimates.')
    ->middleware([
        'auth:api_admin',
        'auth.jwt:api_admin',
        'organization.context',
        'budget-estimates.active'
    ])
    ->group(function () {
        
        // ============================================
        // ОСНОВНОЙ CRUD СМЕТ
        // ============================================
        Route::prefix('estimates')->group(function () {
            Route::get('/', [EstimateController::class, 'index'])->name('index');
            Route::post('/', [EstimateController::class, 'store'])->name('store');
            Route::get('/{estimate}', [EstimateController::class, 'show'])->name('show');
            Route::put('/{estimate}', [EstimateController::class, 'update'])->name('update');
            Route::delete('/{estimate}', [EstimateController::class, 'destroy'])->name('destroy');
            
            // Специальные действия
            Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('duplicate');
            Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate'])->name('recalculate');
            Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard'])->name('dashboard');
            Route::get('/{estimate}/structure', [EstimateController::class, 'structure'])->name('structure');
            
            // ============================================
            // РАЗДЕЛЫ СМЕТЫ
            // ============================================
            Route::prefix('{estimate}/sections')->name('sections.')->group(function () {
                Route::get('/', [EstimateSectionController::class, 'index'])->name('index');
                Route::post('/', [EstimateSectionController::class, 'store'])->name('store');
            });
            
            // ============================================
            // ПОЗИЦИИ СМЕТЫ
            // ============================================
            Route::prefix('{estimate}/items')->name('items.')->group(function () {
                Route::get('/', [EstimateItemController::class, 'index'])->name('index');
                Route::post('/', [EstimateItemController::class, 'store'])->name('store');
                Route::post('/bulk', [EstimateItemController::class, 'bulkStore'])->name('bulk_store');
            });
            
            // ============================================
            // ИМПОРТ (будет реализован позже)
            // ============================================
            Route::prefix('{estimate}/import')->name('import.')->group(function () {
                // Wizard steps:
                // Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
                // Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
                // Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
                // Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
                // Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
            });
        });
    });

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

