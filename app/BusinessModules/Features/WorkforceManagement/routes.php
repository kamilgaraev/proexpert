<?php

declare(strict_types=1);

use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\Mobile\WorkforceMobileAttendanceController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceAttendanceQrController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceCorporateController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceDirectoryController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceEmployeeController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceHrController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceHrDocumentController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceProController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceSelfServiceController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceSummaryController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceTeamQueueController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceTimekeepingController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceTimekeepingPolicyController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/workforce')
    ->name('admin.workforce.')
    ->middleware(AdminRouteStack::middleware())
    ->group(function (): void {
        Route::get('/summary', WorkforceSummaryController::class)->middleware('authorize:workforce.view');

        Route::prefix('hr')->group(function (): void {
            Route::get('owners', [WorkforceDirectoryController::class, 'owners'])->middleware('authorize:workforce.view');
            Route::get('safety-work-types', [WorkforceDirectoryController::class, 'safetyWorkTypes'])->middleware('authorize:workforce.settings.manage');
            foreach ([
                ['get', 'employees/{employeeId}/history', 'history', 'workforce.hr.private.read'],
                ['post', 'employees/{employeeId}/relations', 'recordRelation', 'workforce.hr.manage'],
                ['get', 'employees/{employeeId}/safety-validity', 'validity', 'workforce.view'],
                ['get', 'recruiting', 'recruiting', 'workforce.hr.recruitment.manage'],
                ['post', 'vacancies', 'storeVacancy', 'workforce.hr.recruitment.manage'],
                ['post', 'vacancies/{vacancyId}/close', 'closeVacancy', 'workforce.hr.recruitment.manage'],
                ['post', 'candidates', 'storeCandidate', 'workforce.hr.recruitment.manage'],
                ['post', 'candidates/{candidateId}/stage', 'moveCandidate', 'workforce.hr.recruitment.manage'],
                ['get', 'employees/{employeeId}/lifecycle', 'lifecycle', 'workforce.hr.lifecycle.manage'],
                ['post', 'employees/{employeeId}/checklist', 'storeChecklist', 'workforce.hr.lifecycle.manage'],
                ['post', 'employees/{employeeId}/checklist/{itemId}/complete', 'completeChecklist', 'workforce.hr.lifecycle.manage'],
                ['post', 'employees/{employeeId}/development', 'storeDevelopment', 'workforce.hr.lifecycle.manage'],
            ] as [$verb, $uri, $method, $permission]) {
                Route::$verb($uri, [WorkforceHrController::class, $method])
                    ->whereNumber(['employeeId', 'vacancyId', 'candidateId', 'itemId'])->middleware('authorize:'.$permission);
            }
            Route::prefix('employees/{employeeId}/documents')->whereNumber('employeeId')->group(function (): void {
                foreach ([
                    ['get', '', 'index', 'read'], ['post', '', 'store', 'manage'],
                    ['post', 'upload', 'upload', 'manage'], ['post', 'printable', 'printable', 'manage'],
                    ['get', '{documentId}/download', 'download', 'read'],
                    ['post', '{documentId}/paper-evidence', 'paperEvidence', 'manage'],
                    ['post', '{documentId}/acknowledge', 'acknowledge', 'manage'],
                ] as [$verb, $uri, $method, $permission]) {
                    Route::$verb($uri, [WorkforceHrDocumentController::class, $method])
                        ->whereNumber('documentId')->middleware('authorize:workforce.hr.documents.'.$permission);
                }
            });
        });

        Route::prefix('timekeeping')->group(function (): void {
            Route::get('policy', [WorkforceTimekeepingPolicyController::class, 'show'])->middleware('authorize:workforce.settings.manage');
            Route::put('policy', [WorkforceTimekeepingPolicyController::class, 'update'])->middleware('authorize:workforce.settings.manage');
            foreach ([
                ['get', 'periods', 'index', 'view'], ['post', 'periods', 'store', 'manage'],
                ['get', 'periods/{period}', 'show', 'view'], ['post', 'periods/{period}/refresh', 'refresh', 'manage'],
                ['post', 'periods/{period}/actions', 'action', 'view'], ['post', 'periods/{period}/cells', 'cell', 'manage'],
                ['post', 'periods/{period}/documents', 'export', 'export'], ['post', 'periods/{period}/signed-file', 'uploadSignedFile', 'approve'],
                ['get', 'documents/{document}/download', 'download', 'export'],
            ] as [$verb, $uri, $method, $permission]) {
                Route::$verb($uri, [WorkforceTimekeepingController::class, $method])
                    ->whereNumber(['period', 'document'])->middleware('authorize:workforce.timekeeping.'.$permission);
            }
        });

        Route::get('/team-queue/scopes', [WorkforceTeamQueueController::class, 'scopes'])->middleware('authorize:workforce.hr.manage');
        Route::post('/team-queue/scopes', [WorkforceTeamQueueController::class, 'storeScope'])->middleware('authorize:workforce.hr.manage');
        Route::get('/team-queue', [WorkforceTeamQueueController::class, 'index'])->middleware('authorize:workforce.team-queue.view');
        Route::post('/team-queue/{requestId}/decision', [WorkforceTeamQueueController::class, 'decision'])->whereNumber('requestId')->middleware('authorize:workforce.team-queue.view');
        Route::get('/employees', [WorkforceEmployeeController::class, 'index'])
            ->middleware('authorize:workforce.view');
        Route::post('/employees', [WorkforceEmployeeController::class, 'store'])
            ->middleware('authorize:workforce.employees.basic');
        Route::get('/employees/{employeeId}', [WorkforceEmployeeController::class, 'show'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.view');
        Route::get('/employees/{employeeId}/card', [WorkforceEmployeeController::class, 'card'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.view');
        Route::put('/employees/{employeeId}', [WorkforceEmployeeController::class, 'update'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.employees.basic');
        Route::post('/employees/{employeeId}/dismiss', [WorkforceEmployeeController::class, 'dismiss'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.employees.basic');

        Route::get('/departments', [WorkforceProController::class, 'departments'])
            ->middleware('authorize:workforce.view');
        Route::post('/departments', [WorkforceProController::class, 'storeDepartment'])
            ->middleware('authorize:workforce.structure.manage');
        Route::put('/departments/{departmentId}', [WorkforceProController::class, 'updateDepartment'])
            ->whereNumber('departmentId')
            ->middleware('authorize:workforce.structure.manage');
        Route::get('/positions', [WorkforceProController::class, 'positions'])
            ->middleware('authorize:workforce.view');
        Route::post('/positions', [WorkforceProController::class, 'storePosition'])
            ->middleware('authorize:workforce.structure.manage');
        Route::put('/positions/{positionId}', [WorkforceProController::class, 'updatePosition'])
            ->whereNumber('positionId')
            ->middleware('authorize:workforce.structure.manage');
        Route::get('/staff-units', [WorkforceProController::class, 'staffUnits'])
            ->middleware('authorize:workforce.view');
        Route::post('/staff-units', [WorkforceProController::class, 'storeStaffUnit'])
            ->middleware('authorize:workforce.structure.manage');
        Route::put('/staff-units/{staffUnitId}', [WorkforceProController::class, 'updateStaffUnit'])
            ->whereNumber('staffUnitId')
            ->middleware('authorize:workforce.structure.manage');
        Route::post('/employee-assignments', [WorkforceProController::class, 'storeEmployeeAssignment'])
            ->middleware('authorize:workforce.structure.manage');
        Route::put('/employee-assignments/{assignmentId}', [WorkforceProController::class, 'updateEmployeeAssignment'])
            ->whereNumber('assignmentId')
            ->middleware('authorize:workforce.structure.manage');
        Route::get('/schedule-calendar', [WorkforceProController::class, 'scheduleCalendar'])
            ->middleware('authorize:workforce.view');
        Route::get('/attendance-sheet', [WorkforceProController::class, 'attendanceSheet'])
            ->middleware('authorize:workforce.view');
        Route::get('/attendance/qr-scans', [WorkforceAttendanceQrController::class, 'qrScans'])
            ->middleware('authorize:workforce.audit.view');
        Route::get('/employees/{employeeId}/attendance-corrections', [WorkforceProController::class, 'attendanceCorrections'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.view');
        Route::post('/employees/{employeeId}/attendance-corrections', [WorkforceProController::class, 'storeAttendanceCorrection'])
            ->whereNumber('employeeId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/work-schedules', [WorkforceProController::class, 'workSchedules'])
            ->middleware('authorize:workforce.view');
        Route::post('/work-schedules', [WorkforceProController::class, 'storeWorkSchedule'])
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/work-schedules/{scheduleId}/days', [WorkforceProController::class, 'storeWorkScheduleDay'])
            ->whereNumber('scheduleId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/absences', [WorkforceProController::class, 'absences'])
            ->middleware('authorize:workforce.view');
        Route::post('/absences', [WorkforceProController::class, 'storeAbsence'])
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/absences/{absenceId}/approve', [WorkforceProController::class, 'approveAbsence'])
            ->whereNumber('absenceId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/absences/{absenceId}/cancel', [WorkforceProController::class, 'cancelAbsence'])
            ->whereNumber('absenceId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/business-trips', [WorkforceProController::class, 'businessTrips'])
            ->middleware('authorize:workforce.view');
        Route::post('/business-trips', [WorkforceProController::class, 'storeBusinessTrip'])
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/business-trips/{tripId}/approve', [WorkforceProController::class, 'approveBusinessTrip'])
            ->whereNumber('tripId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/business-trips/{tripId}/cancel', [WorkforceProController::class, 'cancelBusinessTrip'])
            ->whereNumber('tripId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/orders', [WorkforceProController::class, 'orders'])
            ->middleware('authorize:workforce.view');
        Route::post('/orders', [WorkforceProController::class, 'storeOrder'])
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/orders/{orderId}/approve', [WorkforceProController::class, 'approveOrder'])
            ->whereNumber('orderId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/orders/{orderId}/apply', [WorkforceProController::class, 'applyOrder'])
            ->whereNumber('orderId')
            ->middleware('authorize:workforce.hr.manage');
        Route::post('/orders/{orderId}/cancel', [WorkforceProController::class, 'cancelOrder'])
            ->whereNumber('orderId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/payroll-periods', [WorkforceProController::class, 'payrollPeriods'])
            ->middleware('authorize:workforce.payroll-source.view');
        Route::post('/payroll-periods', [WorkforceProController::class, 'storePayrollPeriod'])
            ->middleware('authorize:workforce.payroll-source.manage');
        Route::get('/payroll-periods/{periodId}', [WorkforceProController::class, 'showPayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.view');
        Route::post('/payroll-periods/{periodId}/build-source', [WorkforceProController::class, 'buildPayrollSource'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.manage');
        Route::post('/payroll-periods/{periodId}/validate', [WorkforceProController::class, 'validatePayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.validate');
        Route::get('/payroll-periods/{periodId}/source-rows', [WorkforceProController::class, 'payrollSourceRows'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.view');
        Route::get('/payroll-periods/{periodId}/validation-issues', [WorkforceProController::class, 'payrollValidationIssues'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.view');
        Route::get('/payroll-periods/{periodId}/statements', [WorkforceProController::class, 'payrollStatements'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.view');
        Route::post('/payroll-periods/{periodId}/statements', [WorkforceProController::class, 'createPayrollStatement'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.manage');
        Route::post('/payroll-periods/{periodId}/lock', [WorkforceCorporateController::class, 'lockPayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.lock');
        Route::post('/payroll-periods/{periodId}/reopen', [WorkforceCorporateController::class, 'reopenPayrollPeriod'])
            ->whereNumber('periodId')->middleware('authorize:workforce.payroll-source.correct');
        Route::get('/payroll-periods/{periodId}/revisions', [WorkforceCorporateController::class, 'payrollRevisions'])->whereNumber('periodId')->middleware('authorize:workforce.payroll-source.view');
        Route::get('/payroll-periods/{periodId}/revisions/{revision}', [WorkforceCorporateController::class, 'payrollRevision'])->whereNumber(['periodId', 'revision'])->middleware('authorize:workforce.payroll-source.view');
        Route::post('/payroll-periods/{periodId}/export-packages', [WorkforceCorporateController::class, 'createExportPackage'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.exports.generate');
        Route::get('/export-packages', [WorkforceCorporateController::class, 'exportPackages'])
            ->middleware('authorize:workforce.exports.view');
        Route::get('/export-packages/{packageId}', [WorkforceCorporateController::class, 'showExportPackage'])
            ->whereNumber('packageId')
            ->middleware('authorize:workforce.exports.view');
        Route::get('/export-packages/{packageId}/files/{fileId}/download', [WorkforceCorporateController::class, 'downloadExportFile'])
            ->whereNumber('packageId')
            ->whereNumber('fileId')
            ->middleware('authorize:workforce.exports.generate');
        Route::post('/export-packages/{packageId}/mark-sent', [WorkforceCorporateController::class, 'markSent'])
            ->whereNumber('packageId')
            ->middleware('authorize:workforce.exports.generate');
        Route::post('/export-packages/{packageId}/mark-accepted', [WorkforceCorporateController::class, 'markAccepted'])
            ->whereNumber('packageId')
            ->middleware('authorize:workforce.exports.approve');
        Route::post('/export-packages/{packageId}/mark-rejected', [WorkforceCorporateController::class, 'markRejected'])
            ->whereNumber('packageId')
            ->middleware('authorize:workforce.exports.approve');
        Route::get('/accounting-mappings', [WorkforceCorporateController::class, 'accountingMappings'])
            ->middleware('authorize:workforce.settings.manage');
        Route::post('/accounting-mappings', [WorkforceCorporateController::class, 'storeAccountingMapping'])
            ->middleware('authorize:workforce.settings.manage');
        Route::put('/accounting-mappings/{mappingId}', [WorkforceCorporateController::class, 'updateAccountingMapping'])
            ->whereNumber('mappingId')
            ->middleware('authorize:workforce.settings.manage');
    });

Route::prefix('api/v1/mobile/workforce/self-service')->name('mobile.workforce.self-service.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->group(function (): void {
        Route::get('/', [WorkforceSelfServiceController::class, 'index'])->middleware('authorize:workforce.self-service.view');
        Route::post('/requests', [WorkforceSelfServiceController::class, 'store'])->middleware('authorize:workforce.self-service.request');
        Route::get('/requests', [WorkforceSelfServiceController::class, 'requests'])->middleware('authorize:workforce.self-service.view');
        Route::get('/documents/{documentId}/download', [WorkforceSelfServiceController::class, 'download'])->whereNumber('documentId')->middleware('authorize:workforce.self-service.view');
    });

Route::prefix('api/v1/mobile/workforce/attendance')
    ->name('mobile.workforce.attendance.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->group(function (): void {
        Route::post('/qr', [WorkforceMobileAttendanceController::class, 'issueQr'])
            ->middleware('mobile.project-authorize:workforce.attendance.qr.self');
        Route::post('/qr/scan', [WorkforceMobileAttendanceController::class, 'scanQr'])
            ->middleware('mobile.project-authorize:workforce.attendance.scan-confirm,qr_token');
        Route::post('/self', [WorkforceMobileAttendanceController::class, 'selfAttendance'])
            ->middleware('mobile.project-authorize:workforce.attendance.self');
        Route::get('/history', [WorkforceMobileAttendanceController::class, 'history'])
            ->middleware('mobile.project-authorize:workforce.attendance.self');
    });
