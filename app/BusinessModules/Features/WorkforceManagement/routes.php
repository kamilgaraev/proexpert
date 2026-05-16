<?php

declare(strict_types=1);

use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceEmployeeController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceCorporateController;
use App\BusinessModules\Features\WorkforceManagement\Http\Controllers\WorkforceProController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/workforce')
    ->name('admin.workforce.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
    ->group(function (): void {
        Route::get('/employees', [WorkforceEmployeeController::class, 'index'])
            ->middleware('authorize:workforce.view');
        Route::post('/employees', [WorkforceEmployeeController::class, 'store'])
            ->middleware('authorize:workforce.employees.basic');
        Route::get('/employees/{employeeId}', [WorkforceEmployeeController::class, 'show'])
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
            ->middleware('authorize:workforce.attendance.manage');
        Route::post('/orders/{orderId}/cancel', [WorkforceProController::class, 'cancelOrder'])
            ->whereNumber('orderId')
            ->middleware('authorize:workforce.attendance.manage');
        Route::get('/payroll-periods', [WorkforceProController::class, 'payrollPeriods'])
            ->middleware('authorize:workforce.view');
        Route::post('/payroll-periods', [WorkforceProController::class, 'storePayrollPeriod'])
            ->middleware('authorize:workforce.payroll-source.manage');
        Route::get('/payroll-periods/{periodId}', [WorkforceProController::class, 'showPayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.view');
        Route::post('/payroll-periods/{periodId}/build-source', [WorkforceProController::class, 'buildPayrollSource'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.manage');
        Route::post('/payroll-periods/{periodId}/validate', [WorkforceProController::class, 'validatePayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.validate');
        Route::get('/payroll-periods/{periodId}/source-rows', [WorkforceProController::class, 'payrollSourceRows'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.view');
        Route::get('/payroll-periods/{periodId}/validation-issues', [WorkforceProController::class, 'payrollValidationIssues'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.view');
        Route::post('/payroll-periods/{periodId}/lock', [WorkforceCorporateController::class, 'lockPayrollPeriod'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.payroll-source.lock');
        Route::post('/payroll-periods/{periodId}/export-packages', [WorkforceCorporateController::class, 'createExportPackage'])
            ->whereNumber('periodId')
            ->middleware('authorize:workforce.exports.generate');
        Route::get('/export-packages', [WorkforceCorporateController::class, 'exportPackages'])
            ->middleware('authorize:workforce.view');
        Route::get('/export-packages/{packageId}', [WorkforceCorporateController::class, 'showExportPackage'])
            ->whereNumber('packageId')
            ->middleware('authorize:workforce.view');
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
