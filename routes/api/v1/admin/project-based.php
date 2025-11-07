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
// Estimate controllers moved to BudgetEstimatesServiceProvider
// use App\Http\Controllers\Api\V1\Admin\EstimateController;
// use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
// use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
// use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Controllers\Api\V1\Admin\Schedule\ProjectScheduleController;
use App\Http\Controllers\Api\V1\Admin\Schedule\ScheduleTaskController;
use App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController;

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
    
    // === SCHEDULES ===
    Route::prefix('schedules')->group(function () {
        Route::get('/', [ProjectScheduleController::class, 'index']);
        Route::post('/', [ProjectScheduleController::class, 'store']);
        Route::post('/from-estimate', [ScheduleEstimateController::class, 'createFromEstimate']);
        Route::get('/{schedule}', [ProjectScheduleController::class, 'show']);
        Route::put('/{schedule}', [ProjectScheduleController::class, 'update']);
        Route::delete('/{schedule}', [ProjectScheduleController::class, 'destroy']);
        
        // Специальные методы графика
        Route::post('/{schedule}/critical-path', [ProjectScheduleController::class, 'calculateCriticalPath']);
        Route::post('/{schedule}/baseline', [ProjectScheduleController::class, 'saveBaseline']);
        Route::delete('/{schedule}/baseline', [ProjectScheduleController::class, 'clearBaseline']);
        
        // Задачи графика
        Route::get('/{schedule}/tasks', [ProjectScheduleController::class, 'tasks']);
        Route::post('/{schedule}/tasks', [ProjectScheduleController::class, 'storeTask']);
        Route::get('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'show']);
        Route::put('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'update']);
        Route::patch('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'destroy']);
        
        // Зависимости и ресурсы
        Route::get('/{schedule}/dependencies', [ProjectScheduleController::class, 'dependencies']);
        Route::post('/{schedule}/dependencies', [ProjectScheduleController::class, 'storeDependency']);
        Route::get('/{schedule}/resource-conflicts', [ProjectScheduleController::class, 'resourceConflicts']);
        
        // Интеграция со сметой
        Route::post('/{schedule}/sync-from-estimate', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'syncFromEstimate']);
        Route::post('/{schedule}/sync-to-estimate', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'syncToEstimate']);
        Route::get('/{schedule}/estimate-conflicts', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'getConflicts']);
        Route::get('/{schedule}/estimate-info', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'getEstimateInfo']);
    });
    
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
    // Маршруты estimates перенесены в BudgetEstimatesServiceProvider::loadProjectBasedRoutes()
    // Это позволяет модулю контролировать свои маршруты независимо
    
    // === PROJECT EVENTS CALENDAR ===
    Route::prefix('events')->group(function () {
        Route::get('/', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'index']);
        Route::post('/', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'store']);
        Route::get('/calendar', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'calendar']);
        Route::get('/upcoming', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'upcoming']);
        Route::get('/today', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'today']);
        Route::get('/statistics', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'statistics']);
        Route::get('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'show']);
        Route::put('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'update']);
        Route::delete('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'destroy']);
        Route::get('/{event}/conflicts', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'conflicts']);
    });
});

