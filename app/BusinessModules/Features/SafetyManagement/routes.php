<?php

declare(strict_types=1);

use App\BusinessModules\Features\SafetyManagement\Http\Controllers\SafetyManagementController;
use App\BusinessModules\Features\SafetyManagement\Http\Controllers\Mobile\SafetyManagementController as MobileSafetyManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/safety-management')
    ->name('admin.safety_management.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'safety-management.active'])
    ->group(function (): void {
        Route::get('/work-permits', [SafetyManagementController::class, 'permits'])
            ->middleware('authorize:safety-management.view')
            ->name('work_permits.index');
        Route::post('/work-permits', [SafetyManagementController::class, 'storePermit'])
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.store');
        Route::post('/work-permits/{id}/submit', [SafetyManagementController::class, 'submitPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.submit');
        Route::post('/work-permits/{id}/approve', [SafetyManagementController::class, 'approvePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.approve');
        Route::post('/work-permits/{id}/activate', [SafetyManagementController::class, 'activatePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.activate');
        Route::post('/work-permits/{id}/suspend', [SafetyManagementController::class, 'suspendPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.suspend');
        Route::post('/work-permits/{id}/resume', [SafetyManagementController::class, 'resumePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.resume');
        Route::post('/work-permits/{id}/reject', [SafetyManagementController::class, 'rejectPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.reject');
        Route::post('/work-permits/{id}/close', [SafetyManagementController::class, 'closePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.close');

        Route::get('/incidents', [SafetyManagementController::class, 'incidents'])
            ->middleware('authorize:safety-management.view')
            ->name('incidents.index');
        Route::post('/incidents', [SafetyManagementController::class, 'storeIncident'])
            ->middleware('authorize:safety-management.incidents.create')
            ->name('incidents.store');
        Route::post('/incidents/{id}/triage', [SafetyManagementController::class, 'triageIncident'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('incidents.triage');
        Route::post('/incidents/{id}/start-investigation', [SafetyManagementController::class, 'startIncidentInvestigation'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('incidents.start_investigation');
        Route::post('/incidents/{id}/corrective-actions', [SafetyManagementController::class, 'startCorrectiveActions'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('incidents.corrective_actions');
        Route::post('/incidents/{id}/cancel', [SafetyManagementController::class, 'cancelIncident'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('incidents.cancel');
        Route::post('/incidents/{id}/close', [SafetyManagementController::class, 'closeIncident'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('incidents.close');

        Route::get('/violations', [SafetyManagementController::class, 'violations'])
            ->middleware('authorize:safety-management.view')
            ->name('violations.index');
        Route::post('/violations', [SafetyManagementController::class, 'storeViolation'])
            ->middleware('authorize:safety-management.violations.create')
            ->name('violations.store');
        Route::post('/violations/{id}/resolve', [SafetyManagementController::class, 'resolveViolation'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.violations.resolve')
            ->name('violations.resolve');

        Route::get('/briefings', [SafetyManagementController::class, 'briefings'])
            ->middleware('authorize:safety-management.view')
            ->name('briefings.index');
        Route::post('/briefings', [SafetyManagementController::class, 'storeBriefing'])
            ->middleware('authorize:safety-management.briefings.manage')
            ->name('briefings.store');

        Route::get('/corrective-actions', [SafetyManagementController::class, 'correctiveActions'])
            ->middleware('authorize:safety-management.view')
            ->name('corrective_actions.index');
        Route::post('/corrective-actions', [SafetyManagementController::class, 'storeCorrectiveAction'])
            ->middleware('authorize:safety-management.incidents.review')
            ->name('corrective_actions.store');
        Route::post('/corrective-actions/{id}/resolve', [SafetyManagementController::class, 'resolveCorrectiveAction'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.violations.resolve')
            ->name('corrective_actions.resolve');
        Route::post('/corrective-actions/{id}/verify', [SafetyManagementController::class, 'verifyCorrectiveAction'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.incidents.review')
            ->name('corrective_actions.verify');
    });

Route::prefix('api/v1/mobile/safety-management')
    ->name('mobile.safety_management.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'safety-management.active'])
    ->group(function (): void {
        Route::get('/work-permits', [MobileSafetyManagementController::class, 'permits'])
            ->middleware('authorize:safety-management.view')
            ->name('work_permits.index');
        Route::get('/work-permits/{id}', [MobileSafetyManagementController::class, 'showPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.view')
            ->name('work_permits.show');
        Route::post('/work-permits/{id}/submit', [MobileSafetyManagementController::class, 'submitPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.submit');
        Route::post('/work-permits/{id}/approve', [MobileSafetyManagementController::class, 'approvePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.approve');
        Route::post('/work-permits/{id}/activate', [MobileSafetyManagementController::class, 'activatePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.activate');
        Route::post('/work-permits/{id}/suspend', [MobileSafetyManagementController::class, 'suspendPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.suspend');
        Route::post('/work-permits/{id}/resume', [MobileSafetyManagementController::class, 'resumePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.resume');
        Route::post('/work-permits/{id}/reject', [MobileSafetyManagementController::class, 'rejectPermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.reject');
        Route::post('/work-permits/{id}/close', [MobileSafetyManagementController::class, 'closePermit'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.close');
        Route::get('/incidents', [MobileSafetyManagementController::class, 'incidents'])
            ->middleware('authorize:safety-management.view')
            ->name('incidents.index');
        Route::post('/incidents', [MobileSafetyManagementController::class, 'storeIncident'])
            ->middleware('authorize:safety-management.incidents.create')
            ->name('incidents.store');
        Route::get('/violations', [MobileSafetyManagementController::class, 'violations'])
            ->middleware('authorize:safety-management.view')
            ->name('violations.index');
        Route::post('/violations', [MobileSafetyManagementController::class, 'storeViolation'])
            ->middleware('authorize:safety-management.violations.create')
            ->name('violations.store');
        Route::post('/violations/{id}/resolve', [MobileSafetyManagementController::class, 'resolveViolation'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.violations.resolve')
            ->name('violations.resolve');
    });
