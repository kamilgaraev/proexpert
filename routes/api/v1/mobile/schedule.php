<?php

use App\Http\Controllers\Api\V1\Mobile\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('/schedule/tasks/{task}', [ScheduleController::class, 'showTask'])
        ->whereNumber('task')
        ->middleware('authorize:schedule.view')
        ->name('schedule.tasks.show');
    Route::post('/schedule/{schedule_id}/tasks', [ScheduleController::class, 'storeTask'])
        ->whereNumber('schedule_id')
        ->middleware('authorize:schedule.edit')
        ->name('schedule.tasks.store');
    Route::patch('/schedule/tasks/{task}', [ScheduleController::class, 'updateTask'])
        ->whereNumber('task')
        ->middleware('authorize:schedule.edit')
        ->name('schedule.tasks.update');
    Route::get('/schedule/daily-plans', [ScheduleController::class, 'dailyPlans'])->name('schedule.daily-plans');
    Route::patch('/schedule/daily-plan-assignments/{assignment}/fact', [ScheduleController::class, 'recordAssignmentFact'])
        ->whereNumber('assignment')
        ->middleware('authorize:schedule.daily_plan.manage')
        ->name('schedule.daily-plan-assignments.fact');
    Route::post('/schedule/daily-plans/{dailyPlan}/submit', [ScheduleController::class, 'submitDailyPlan'])
        ->whereNumber('dailyPlan')
        ->middleware('authorize:schedule.daily_plan.manage')
        ->name('schedule.daily-plans.submit');
    Route::post('/schedule/work-constraints/{constraint}/linked-action', [ScheduleController::class, 'createLinkedConstraintAction'])
        ->whereNumber('constraint')
        ->middleware('authorize:schedule.daily_plan.manage')
        ->name('schedule.work-constraints.linked-action');
    Route::get('/schedule/{scheduleId}', [ScheduleController::class, 'show'])
        ->whereNumber('scheduleId')
        ->name('schedule.show');
});
