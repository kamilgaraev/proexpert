<?php

use App\Http\Controllers\Api\V1\Mobile\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
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
