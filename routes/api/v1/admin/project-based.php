<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Controllers\Api\V1\Admin\CompletedWorkController;
use App\Http\Controllers\Api\V1\Admin\ProjectOrganizationController;
use App\Http\Controllers\Api\V1\Admin\MaterialAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\CustomReportController;
use App\Http\Controllers\Api\V1\Admin\SpecificationController;
use App\Http\Controllers\Api\V1\Admin\AgreementController;
use App\Http\Controllers\Api\V1\Admin\ProjectContextController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractPerformanceActController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractPaymentController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractSpecificationController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractStateEventController;
use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
use App\Http\Controllers\Api\V1\Admin\EstimateItemController;

/**
 * PROJECT-BASED ROUTES
 * 
 * Все роуты внутри проекта. Требуют project.context middleware.
 * URL Pattern: /api/v1/admin/projects/{project}/...
 * 
 * Middleware автоматически:
 * - Проверяет доступ организации к проекту
 * - Инъектирует ProjectContext в request
 * - Валидирует права доступа по роли
 */

Route::prefix('projects/{project}')->middleware(['project.context'])->group(function () {
    
    // === PROJECT CONTEXT & META ===
    Route::get('/context', [ProjectContextController::class, 'getContext']);
    Route::get('/form-meta', [ProjectContextController::class, 'getFormMeta']);
    Route::get('/permissions', [ProjectContextController::class, 'getPermissions']);
    
    // === PROJECT PARTICIPANTS ===
    Route::prefix('participants')->group(function () {
        Route::get('/', [ProjectOrganizationController::class, 'index']);
        Route::post('/', [ProjectOrganizationController::class, 'store']);
        Route::get('/{organization}', [ProjectOrganizationController::class, 'show']);
        Route::put('/{organization}', [ProjectOrganizationController::class, 'update']);
        Route::patch('/{organization}/role', [ProjectOrganizationController::class, 'updateRole']);
        Route::delete('/{organization}', [ProjectOrganizationController::class, 'destroy']);
        Route::post('/{organization}/activate', [ProjectOrganizationController::class, 'activate']);
        Route::post('/{organization}/deactivate', [ProjectOrganizationController::class, 'deactivate']);
    });
    
    // === CONTRACTS ===
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index']);
        Route::post('/', [ContractController::class, 'store']);
        Route::get('/{contract}', [ContractController::class, 'show']);
        Route::put('/{contract}', [ContractController::class, 'update']);
        Route::delete('/{contract}', [ContractController::class, 'destroy']);
        
        // Contract Performance Acts
        Route::prefix('{contract}/performance-acts')->group(function () {
            Route::get('/', [ContractPerformanceActController::class, 'index']);
            Route::post('/', [ContractPerformanceActController::class, 'store']);
            Route::get('/{performance_act}', [ContractPerformanceActController::class, 'show']);
            Route::put('/{performance_act}', [ContractPerformanceActController::class, 'update']);
            Route::delete('/{performance_act}', [ContractPerformanceActController::class, 'destroy']);
        });
        
        Route::get('/{contract}/available-works-for-acts', [ContractPerformanceActController::class, 'availableWorks']);
        
        // Contract Payments
        Route::prefix('{contract}/payments')->group(function () {
            Route::get('/', [ContractPaymentController::class, 'index']);
            Route::post('/', [ContractPaymentController::class, 'store']);
            Route::get('/{payment}', [ContractPaymentController::class, 'show']);
            Route::put('/{payment}', [ContractPaymentController::class, 'update']);
            Route::delete('/{payment}', [ContractPaymentController::class, 'destroy']);
        });
        
        // Contract Specifications
        Route::prefix('{contract}/specifications')->group(function () {
            Route::get('/', [ContractSpecificationController::class, 'index']);
            Route::post('/', [ContractSpecificationController::class, 'store']);
            Route::post('/attach', [ContractSpecificationController::class, 'attach']);
            Route::delete('/{specification}', [ContractSpecificationController::class, 'destroy']);
        });
        
        // Contract State Events (Event Sourcing)
        Route::prefix('{contract}/state-events')->group(function () {
            Route::get('/', [ContractStateEventController::class, 'index']);
            Route::get('/timeline', [ContractStateEventController::class, 'timeline']);
        });
        
        // Contract State
        Route::prefix('{contract}')->group(function () {
            Route::get('/state', [ContractStateEventController::class, 'currentState']);
            Route::get('/state/at-date', [ContractStateEventController::class, 'stateAtDate']);
        });
    });
    
    // === COMPLETED WORKS ===
    Route::prefix('works')->group(function () {
        Route::get('/', [CompletedWorkController::class, 'index']);
        Route::post('/', [CompletedWorkController::class, 'store']);
        Route::get('/{completedWork}', [CompletedWorkController::class, 'show']);
        Route::put('/{completedWork}', [CompletedWorkController::class, 'update']);
        Route::delete('/{completedWork}', [CompletedWorkController::class, 'destroy']);
        Route::post('/bulk', [CompletedWorkController::class, 'bulkCreate']);
        Route::get('/export/excel', [CompletedWorkController::class, 'exportExcel']);
    });
    
    // === SPECIFICATIONS ===
    Route::prefix('specifications')->group(function () {
        Route::get('/', [SpecificationController::class, 'index']);
        Route::post('/', [SpecificationController::class, 'store']);
        Route::get('/{specification}', [SpecificationController::class, 'show']);
        Route::put('/{specification}', [SpecificationController::class, 'update']);
        Route::delete('/{specification}', [SpecificationController::class, 'destroy']);
    });
    
    // === AGREEMENTS (в контексте проекта) ===
    Route::prefix('agreements')->group(function () {
        Route::get('/', [AgreementController::class, 'index']);
        Route::post('/', [AgreementController::class, 'store']);
        Route::get('/{agreement}', [AgreementController::class, 'show']);
        Route::put('/{agreement}', [AgreementController::class, 'update']);
        Route::delete('/{agreement}', [AgreementController::class, 'destroy']);
    });
    
    // === SCHEDULE ===
    // NOTE: Schedule routes имеют свою отдельную структуру в routes/api/v1/admin/schedule.php
    // Если нужна project-scoped version, используйте ProjectScheduleController
    
    // === MATERIAL ANALYTICS (в контексте проекта) ===
    Route::prefix('analytics')->group(function () {
        Route::get('/materials', [MaterialAnalyticsController::class, 'getMaterialAnalytics']);
        Route::get('/costs', [MaterialAnalyticsController::class, 'getCostAnalytics']);
        Route::get('/usage', [MaterialAnalyticsController::class, 'getUsageAnalytics']);
    });
    
    // === CUSTOM REPORTS (в контексте проекта) ===
    Route::prefix('reports')->group(function () {
        Route::get('/', [CustomReportController::class, 'index']);
        Route::post('/', [CustomReportController::class, 'store']);
        Route::get('/{report}', [CustomReportController::class, 'show']);
        Route::post('/{report}/generate', [CustomReportController::class, 'generate']);
        Route::delete('/{report}', [CustomReportController::class, 'destroy']);
    });
    
    // === ESTIMATES (сметы в контексте проекта) ===
    Route::prefix('estimates')->group(function () {
        Route::get('/', [EstimateController::class, 'index']);
        Route::post('/', [EstimateController::class, 'store']);
        Route::get('/{estimate}', [EstimateController::class, 'show']);
        Route::put('/{estimate}', [EstimateController::class, 'update']);
        Route::delete('/{estimate}', [EstimateController::class, 'destroy']);
        
        Route::post('/{estimate}/duplicate', [EstimateController::class, 'duplicate']);
        Route::post('/{estimate}/recalculate', [EstimateController::class, 'recalculate']);
        Route::get('/{estimate}/dashboard', [EstimateController::class, 'dashboard']);
        Route::get('/{estimate}/structure', [EstimateController::class, 'structure']);
        
        Route::prefix('{estimate}/sections')->group(function () {
            Route::get('/', [EstimateSectionController::class, 'index']);
            Route::post('/', [EstimateSectionController::class, 'store']);
        });
        
        Route::prefix('{estimate}/items')->group(function () {
            Route::get('/', [EstimateItemController::class, 'index']);
            Route::post('/', [EstimateItemController::class, 'store']);
            Route::post('/bulk', [EstimateItemController::class, 'bulkStore']);
        });
        
        Route::prefix('import')->name('estimates.import.')->group(function () {
            Route::post('/upload', [EstimateImportController::class, 'upload'])->name('upload');
            Route::post('/detect', [EstimateImportController::class, 'detect'])->name('detect');
            Route::post('/map', [EstimateImportController::class, 'map'])->name('map');
            Route::post('/match', [EstimateImportController::class, 'match'])->name('match');
            Route::post('/execute', [EstimateImportController::class, 'execute'])->name('execute');
            Route::get('/status/{jobId}', [EstimateImportController::class, 'status'])->name('status');
            Route::get('/history', [EstimateImportController::class, 'history'])->name('history');
        });
    });
});

