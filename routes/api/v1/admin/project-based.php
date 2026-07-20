<?php

use App\BusinessModules\Features\ScheduleManagement\Http\Controllers\LookaheadPlanningController;
use App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController;
use App\Http\Controllers\Api\V1\Admin\AgreementController;
use App\Http\Controllers\Api\V1\Admin\CompletedWorkController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractPerformanceActController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractSpecificationController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractStateEventController;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Controllers\Api\V1\Admin\ContractFromEstimateController;
use App\Http\Controllers\Api\V1\Admin\MaterialAnalyticsController;
// ContractPaymentController удален - используйте модуль Payments
use App\Http\Controllers\Api\V1\Admin\ProjectContextController;
use App\Http\Controllers\Api\V1\Admin\ProjectOrganizationController;
// Estimate controllers moved to BudgetEstimatesServiceProvider
// use App\Http\Controllers\Api\V1\Admin\EstimateController;
// use App\Http\Controllers\Api\V1\Admin\EstimateSectionController;
// use App\Http\Controllers\Api\V1\Admin\EstimateItemController;
// use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Controllers\Api\V1\Admin\Schedule\ProjectScheduleController;
use App\Http\Controllers\Api\V1\Admin\Schedule\ScheduleTaskController;
use App\Http\Controllers\Api\V1\Admin\SpecificationController;
use Illuminate\Support\Facades\Route;

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
        Route::post('/', [ProjectOrganizationController::class, 'store'])->middleware('authorize:projects.organizations.manage');
        Route::get('/{organization}', [ProjectOrganizationController::class, 'show']);
        Route::put('/{organization}', [ProjectOrganizationController::class, 'update'])->middleware('authorize:projects.organizations.manage');
        Route::patch('/{organization}/role', [ProjectOrganizationController::class, 'updateRole'])->middleware('authorize:projects.organizations.manage');
        Route::delete('/{organization}', [ProjectOrganizationController::class, 'destroy'])->middleware('authorize:projects.organizations.manage');
        Route::post('/{organization}/activate', [ProjectOrganizationController::class, 'activate'])->middleware('authorize:projects.organizations.manage');
        Route::post('/{organization}/deactivate', [ProjectOrganizationController::class, 'deactivate'])->middleware('authorize:projects.organizations.manage');
    });

    // === CONTRACTS ===
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index'])->middleware('authorize:contracts.view,project,project');
        Route::post('/', [ContractController::class, 'store'])->middleware('authorize:contracts.create,project,project');
        Route::post('/from-estimate', [ContractFromEstimateController::class, 'store'])
            ->middleware('authorize:contracts.create,project,project');
        Route::get('/{contract}', [ContractController::class, 'show'])->middleware('authorize:contracts.view,project,project');
        Route::match(['put', 'patch'], '/{contract}', [ContractController::class, 'update'])
            ->middleware('authorize:contracts.edit,project,project');
        Route::delete('/{contract}', [ContractController::class, 'destroyForProject'])
            ->middleware('authorize:contracts.delete,project,project');

        foreach (['activate', 'suspend', 'resume', 'complete', 'terminate'] as $action) {
            Route::post("/{contract}/{$action}", [ContractController::class, 'transition'])
                ->defaults('action', $action)
                ->middleware('authorize:contracts.edit,project,project');
        }

        Route::post('/{contract}/archive', [ContractController::class, 'transition'])
            ->defaults('action', 'archive')
            ->middleware('authorize:contracts.archive,project,project');

        // Contract Performance Acts
        Route::prefix('{contract}/performance-acts')->group(function () {
            Route::get('/', [ContractPerformanceActController::class, 'index'])
                ->middleware('authorize:contracts.performance_acts.view,project,project');
            Route::post('/', [ContractPerformanceActController::class, 'store'])
                ->middleware('authorize:contracts.performance_acts.create,project,project');
            Route::get('/{performance_act}', [ContractPerformanceActController::class, 'show'])
                ->middleware('authorize:contracts.performance_acts.view,project,project');
            Route::match(['put', 'patch'], '/{performance_act}', [ContractPerformanceActController::class, 'update'])
                ->middleware('authorize:contracts.performance_acts.edit,project,project');
            Route::delete('/{performance_act}', [ContractPerformanceActController::class, 'destroy'])
                ->middleware('authorize:contracts.performance_acts.delete,project,project');
        });

        Route::get('/{contract}/available-works-for-acts', [ContractPerformanceActController::class, 'availableWorks'])
            ->middleware('authorize:contracts.performance_acts.create,project,project');

        // УСТАРЕВШИЕ МАРШРУТЫ - УДАЛЕНЫ
        // Contract Payments теперь управляются через модуль Payments
        // Используйте: /api/v1/admin/payments/invoices
        // Старые маршруты projects/{project}/contracts/{contract}/payments больше не поддерживаются

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
        Route::post('/bulk', [CompletedWorkController::class, 'bulkCreate']);
        Route::get('/export/excel', [CompletedWorkController::class, 'exportExcel']);
        Route::get('/{completed_work}', [CompletedWorkController::class, 'showProjectWork']);
        Route::put('/{completed_work}', [CompletedWorkController::class, 'updateProjectWork']);
        Route::delete('/{completed_work}', [CompletedWorkController::class, 'destroyProjectWork']);
        Route::post('/{completed_work}/attach-schedule-task', [CompletedWorkController::class, 'attachScheduleTask']);
        Route::post('/{completed_work}/create-schedule-task', [CompletedWorkController::class, 'createScheduleTaskFromWork']);
    });

    Route::get('schedule-tasks', [CompletedWorkController::class, 'getScheduleTasks']);

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
        Route::post('/{agreement}/apply-changes', [AgreementController::class, 'applyChanges']);
    });

    // === SCHEDULES ===
    Route::prefix('schedules')->group(function () {
        Route::get('/', [ProjectScheduleController::class, 'index'])->middleware('authorize:schedule.view,project,project');
        Route::post('/', [ProjectScheduleController::class, 'store'])->middleware('authorize:schedule.create,project,project');
        Route::post('/from-estimate', [ScheduleEstimateController::class, 'createFromEstimate'])->middleware('authorize:schedule.create,project,project');
        Route::get('/{schedule}', [ProjectScheduleController::class, 'show'])->middleware('authorize:schedule.view,project,project');
        Route::put('/{schedule}', [ProjectScheduleController::class, 'update'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{schedule}', [ProjectScheduleController::class, 'destroy'])->middleware('authorize:schedule.delete,project,project');

        // Специальные методы графика
        Route::post('/{schedule}/critical-path', [ProjectScheduleController::class, 'calculateCriticalPath'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/baseline', [ProjectScheduleController::class, 'saveBaseline'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{schedule}/baseline', [ProjectScheduleController::class, 'clearBaseline'])->middleware('authorize:schedule.edit,project,project');

        // Задачи графика
        Route::get('/{schedule}/tasks', [ProjectScheduleController::class, 'tasks'])->middleware('authorize:schedule.view,project,project');
        Route::post('/{schedule}/tasks', [ProjectScheduleController::class, 'storeTask'])->middleware('authorize:schedule.edit,project,project');
        Route::get('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'show'])->middleware('authorize:schedule.view,project,project');
        Route::put('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'update'])->middleware('authorize:schedule.edit,project,project');
        Route::patch('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'update'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'destroy'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/tasks/{task}/resources', [ScheduleTaskController::class, 'storeResource'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{schedule}/tasks/{task}/resources/{resource}', [ScheduleTaskController::class, 'destroyResource'])->middleware('authorize:schedule.edit,project,project');

        // Зависимости и ресурсы
        Route::get('/{schedule}/dependencies', [ProjectScheduleController::class, 'dependencies'])->middleware('authorize:schedule.view,project,project');
        Route::post('/{schedule}/dependencies', [ProjectScheduleController::class, 'storeDependency'])->middleware('authorize:schedule.edit,project,project');
        Route::put('/{schedule}/dependencies/{dependency}', [ProjectScheduleController::class, 'updateDependency'])->middleware('authorize:schedule.edit,project,project');
        Route::patch('/{schedule}/dependencies/{dependency}', [ProjectScheduleController::class, 'updateDependency'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{schedule}/dependencies/{dependency}', [ProjectScheduleController::class, 'destroyDependency'])->middleware('authorize:schedule.edit,project,project');
        Route::get('/{schedule}/resource-conflicts', [ProjectScheduleController::class, 'resourceConflicts'])->middleware('authorize:schedule.view,project,project');
        Route::get('/{schedule}/export', [ProjectScheduleController::class, 'export'])->middleware('authorize:schedule.export,project,project');

        // Интеграция со сметой
        Route::post('/{schedule}/sync-from-estimate', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'syncFromEstimate'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/sync-to-estimate', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'syncToEstimate'])->middleware('authorize:schedule.edit,project,project');
        Route::get('/{schedule}/estimate-conflicts', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'getConflicts'])->middleware('authorize:schedule.view,project,project');
        Route::get('/{schedule}/estimate-info', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ScheduleEstimateController::class, 'getEstimateInfo'])->middleware('authorize:schedule.view,project,project');

        Route::get('/{schedule}/lookahead-plans', [LookaheadPlanningController::class, 'indexPlans'])->middleware('authorize:schedule.view,project,project');
        Route::post('/{schedule}/lookahead-plans', [LookaheadPlanningController::class, 'storePlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/lookahead-plans/{plan}/tasks', [LookaheadPlanningController::class, 'storePlanTask'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/lookahead-tasks/{planTask}/constraints', [LookaheadPlanningController::class, 'storeConstraint'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans', [LookaheadPlanningController::class, 'storeDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/publish', [LookaheadPlanningController::class, 'publishDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/submit', [LookaheadPlanningController::class, 'submitDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/accept', [LookaheadPlanningController::class, 'acceptDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/return', [LookaheadPlanningController::class, 'returnDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/close', [LookaheadPlanningController::class, 'closeDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::post('/{schedule}/daily-plans/{dailyPlan}/revise', [LookaheadPlanningController::class, 'reviseDailyPlan'])->middleware('authorize:schedule.edit,project,project');
        Route::patch('/{schedule}/daily-plan-assignments/{assignment}/fact', [LookaheadPlanningController::class, 'recordAssignmentFact'])->middleware('authorize:schedule.edit,project,project');
    });

    // === MATERIAL ANALYTICS (в контексте проекта) ===
    Route::prefix('analytics')->group(function () {
        Route::get('/materials', [MaterialAnalyticsController::class, 'getMaterialAnalytics']);
        Route::get('/costs', [MaterialAnalyticsController::class, 'getCostAnalytics']);
        Route::get('/usage', [MaterialAnalyticsController::class, 'getUsageAnalytics']);
    });

    // === ESTIMATES (сметы в контексте проекта) ===
    // Маршруты estimates перенесены в BudgetEstimatesServiceProvider::loadProjectBasedRoutes()
    // Это позволяет модулю контролировать свои маршруты независимо

    // === PROJECT EVENTS CALENDAR ===
    Route::prefix('events')->group(function () {
        Route::get('/', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'index'])->middleware('authorize:schedule.view,project,project');
        Route::post('/', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'store'])->middleware('authorize:schedule.create,project,project');
        Route::get('/calendar', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'calendar'])->middleware('authorize:schedule.view,project,project');
        Route::get('/upcoming', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'upcoming'])->middleware('authorize:schedule.view,project,project');
        Route::get('/today', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'today'])->middleware('authorize:schedule.view,project,project');
        Route::get('/statistics', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'statistics'])->middleware('authorize:schedule.view,project,project');
        Route::get('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'show'])->middleware('authorize:schedule.view,project,project');
        Route::put('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'update'])->middleware('authorize:schedule.edit,project,project');
        Route::delete('/{event}', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'destroy'])->middleware('authorize:schedule.edit,project,project');
        Route::get('/{event}/conflicts', [\App\BusinessModules\Features\ScheduleManagement\Http\Controllers\ProjectEventController::class, 'conflicts'])->middleware('authorize:schedule.view,project,project');
    });
});
