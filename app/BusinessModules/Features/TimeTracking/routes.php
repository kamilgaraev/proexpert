<?php

declare(strict_types=1);

use App\BusinessModules\Features\TimeTracking\Http\Controllers\Mobile\TimeTrackingController;
use App\Http\Controllers\Api\V1\Mobile\MobileTimeEntryApprovalController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/mobile/time-tracking')
    ->name('mobile.time_tracking.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'time-tracking.active'])
    ->group(function (): void {
        Route::get('/entries', [TimeTrackingController::class, 'index'])->name('entries.index');
        Route::get('/daily-summary', [TimeTrackingController::class, 'dailySummary'])->name('daily_summary');
        Route::get('/entries/{entry}', [TimeTrackingController::class, 'show'])->name('entries.show');
        Route::post('/entries', [TimeTrackingController::class, 'store'])->name('entries.store');
        Route::post('/timer/start', [TimeTrackingController::class, 'startTimer'])->name('timer.start');
        Route::post('/entries/{entry}/stop', [TimeTrackingController::class, 'stopTimer'])->name('entries.stop');
        Route::post('/entries/{entry}/submit', [TimeTrackingController::class, 'submit'])->name('entries.submit');
        Route::post('/entries/{entry}/correction', [TimeTrackingController::class, 'correction'])->name('entries.correction');
        Route::get('/pending-approvals', [MobileTimeEntryApprovalController::class, 'index'])
            ->middleware('authorize:time_tracking.view')
            ->name('pending-approvals.index');
        Route::post('/entries/{entry}/approve', [MobileTimeEntryApprovalController::class, 'approve'])
            ->whereNumber('entry')
            ->middleware('authorize:time_tracking.approve')
            ->name('entries.approve');
        Route::post('/entries/{entry}/reject', [MobileTimeEntryApprovalController::class, 'reject'])
            ->whereNumber('entry')
            ->middleware('authorize:time_tracking.reject')
            ->name('entries.reject');
    });
