<?php

use App\Http\Controllers\Api\V1\Mobile\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function () {
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
});
