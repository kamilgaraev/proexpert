<?php

use App\Http\Controllers\Api\V1\Mobile\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/schedule', [ScheduleController::class, 'index'])
        ->middleware('mobile.project-authorize:schedule.view,project_id')
        ->name('schedule.index');
    Route::get('/schedule/tasks/{task}', [ScheduleController::class, 'showTask'])
        ->whereNumber('task')
        ->middleware('mobile.project-authorize:schedule.view,schedule_task,task')
        ->name('schedule.tasks.show');
    Route::post('/schedule/{schedule_id}/tasks', [ScheduleController::class, 'storeTask'])
        ->whereNumber('schedule_id')
        ->middleware('mobile.project-authorize:schedule.edit,project_schedule,schedule_id')
        ->name('schedule.tasks.store');
    Route::patch('/schedule/tasks/{task}', [ScheduleController::class, 'updateTask'])
        ->whereNumber('task')
        ->middleware('mobile.project-authorize:schedule.edit,schedule_task,task')
        ->name('schedule.tasks.update');
    Route::get('/schedule/daily-plans', [ScheduleController::class, 'dailyPlans'])
        ->middleware('mobile.project-authorize:schedule.view,project_id')
        ->name('schedule.daily-plans');
    Route::patch('/schedule/daily-plan-assignments/{assignment}/fact', [ScheduleController::class, 'recordAssignmentFact'])
        ->whereNumber('assignment')
        ->middleware('mobile.project-authorize:schedule.daily_plan.manage,daily_plan_assignment,assignment')
        ->name('schedule.daily-plan-assignments.fact');
    Route::post('/schedule/daily-plans/{dailyPlan}/submit', [ScheduleController::class, 'submitDailyPlan'])
        ->whereNumber('dailyPlan')
        ->middleware('mobile.project-authorize:schedule.daily_plan.manage,daily_plan,dailyPlan')
        ->name('schedule.daily-plans.submit');
    Route::post('/schedule/work-constraints/{constraint}/linked-action', [ScheduleController::class, 'createLinkedConstraintAction'])
        ->whereNumber('constraint')
        ->middleware('mobile.project-authorize:schedule.daily_plan.manage,work_constraint,constraint')
        ->name('schedule.work-constraints.linked-action');
    Route::get('/schedule/{scheduleId}', [ScheduleController::class, 'show'])
        ->whereNumber('scheduleId')
        ->middleware('mobile.project-authorize:schedule.view,project_schedule,scheduleId')
        ->name('schedule.show');
});
