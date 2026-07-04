<?php

declare(strict_types=1);

use App\BusinessModules\Features\SafetyManagement\Http\Controllers\SafetyManagementController;
use App\BusinessModules\Features\SafetyManagement\Http\Controllers\Mobile\SafetyManagementController as MobileSafetyManagementController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/safety-management')
    ->name('admin.safety_management.')
    ->middleware(AdminRouteStack::middleware(['safety-management.active']))
    ->group(function (): void {
        Route::get('/dashboard', [SafetyManagementController::class, 'dashboard'])
            ->middleware('authorize:safety-management.view')
            ->name('dashboard');

        Route::post('/admission/check', [SafetyManagementController::class, 'checkAdmission'])
            ->middleware('authorize:safety-management.view')
            ->name('admission.check');

        Route::get('/requirement-matrices', [SafetyManagementController::class, 'requirementMatrices'])
            ->middleware('authorize:safety-management.view')
            ->name('requirement_matrices.index');
        Route::post('/requirement-matrices', [SafetyManagementController::class, 'storeRequirementMatrix'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('requirement_matrices.store');
        Route::put('/requirement-matrices/{id}', [SafetyManagementController::class, 'updateRequirementMatrix'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('requirement_matrices.update');
        Route::delete('/requirement-matrices/{id}', [SafetyManagementController::class, 'destroyRequirementMatrix'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('requirement_matrices.destroy');

        Route::get('/employee-requirements', [SafetyManagementController::class, 'employeeRequirements'])
            ->middleware('authorize:safety-management.view')
            ->name('employee_requirements.index');
        Route::post('/employee-requirements', [SafetyManagementController::class, 'storeEmployeeRequirement'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('employee_requirements.store');
        Route::put('/employee-requirements/{id}', [SafetyManagementController::class, 'updateEmployeeRequirement'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('employee_requirements.update');
        Route::delete('/employee-requirements/{id}', [SafetyManagementController::class, 'destroyEmployeeRequirement'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('employee_requirements.destroy');

        Route::get('/training-records', [SafetyManagementController::class, 'trainingRecords'])
            ->middleware('authorize:safety-management.view')
            ->name('training_records.index');
        Route::post('/training-records', [SafetyManagementController::class, 'storeTrainingRecord'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('training_records.store');
        Route::put('/training-records/{id}', [SafetyManagementController::class, 'updateTrainingRecord'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('training_records.update');
        Route::delete('/training-records/{id}', [SafetyManagementController::class, 'destroyTrainingRecord'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('training_records.destroy');

        Route::get('/medical-exams', [SafetyManagementController::class, 'medicalExams'])
            ->middleware('authorize:safety-management.view')
            ->name('medical_exams.index');
        Route::post('/medical-exams', [SafetyManagementController::class, 'storeMedicalExam'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('medical_exams.store');
        Route::put('/medical-exams/{id}', [SafetyManagementController::class, 'updateMedicalExam'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('medical_exams.update');
        Route::delete('/medical-exams/{id}', [SafetyManagementController::class, 'destroyMedicalExam'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('medical_exams.destroy');

        Route::get('/ppe-issues', [SafetyManagementController::class, 'ppeIssues'])
            ->middleware('authorize:safety-management.view')
            ->name('ppe_issues.index');
        Route::post('/ppe-issues', [SafetyManagementController::class, 'storePpeIssue'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('ppe_issues.store');
        Route::put('/ppe-issues/{id}', [SafetyManagementController::class, 'updatePpeIssue'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('ppe_issues.update');
        Route::delete('/ppe-issues/{id}', [SafetyManagementController::class, 'destroyPpeIssue'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.settings.manage')
            ->name('ppe_issues.destroy');

        Route::post('/documents/briefing-journal/draft', [SafetyManagementController::class, 'draftBriefingJournal'])
            ->middleware('authorize:safety-management.view')
            ->name('documents.briefing_journal.draft');
        Route::post('/documents/ppe-card/draft', [SafetyManagementController::class, 'draftPpeCard'])
            ->middleware('authorize:safety-management.view')
            ->name('documents.ppe_card.draft');
        Route::post('/documents/violation-act/draft', [SafetyManagementController::class, 'draftViolationAct'])
            ->middleware('authorize:safety-management.view')
            ->name('documents.violation_act.draft');

        Route::get('/inspection-templates', [SafetyManagementController::class, 'inspectionTemplates'])
            ->middleware('authorize:safety-management.view')
            ->name('inspection_templates.index');
        Route::post('/inspection-templates', [SafetyManagementController::class, 'storeInspectionTemplate'])
            ->middleware('authorize:safety-management.settings.manage')
            ->name('inspection_templates.store');

        Route::get('/inspections', [SafetyManagementController::class, 'inspections'])
            ->middleware('authorize:safety-management.view')
            ->name('inspections.index');
        Route::post('/inspections', [SafetyManagementController::class, 'storeInspection'])
            ->middleware('authorize:safety-management.permits.manage')
            ->name('inspections.store');
        Route::post('/inspections/{id}/complete', [SafetyManagementController::class, 'completeInspection'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('inspections.complete');

        Route::get('/inspection-findings', [SafetyManagementController::class, 'inspectionFindings'])
            ->middleware('authorize:safety-management.view')
            ->name('inspection_findings.index');
        Route::post('/inspection-findings', [SafetyManagementController::class, 'storeInspectionFinding'])
            ->middleware('authorize:safety-management.violations.create')
            ->name('inspection_findings.store');
        Route::post('/inspection-findings/{id}/resolve', [SafetyManagementController::class, 'resolveInspectionFinding'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.violations.resolve')
            ->name('inspection_findings.resolve');

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
        Route::put('/work-permits/{id}/participants', [SafetyManagementController::class, 'syncPermitParticipants'])
            ->whereNumber('id')
            ->middleware('authorize:safety-management.permits.manage')
            ->name('work_permits.participants.sync');
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
        Route::get('/dashboard', [MobileSafetyManagementController::class, 'dashboard'])
            ->middleware('authorize:safety-management.view')
            ->name('dashboard');
        Route::get('/my-admission', [MobileSafetyManagementController::class, 'myAdmission'])
            ->middleware('authorize:safety-management.view')
            ->name('my_admission');
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
        Route::get('/inspections', [MobileSafetyManagementController::class, 'inspections'])
            ->middleware('authorize:safety-management.view')
            ->name('inspections.index');
        Route::get('/inspection-findings', [MobileSafetyManagementController::class, 'inspectionFindings'])
            ->middleware('authorize:safety-management.view')
            ->name('inspection_findings.index');
        Route::post('/inspection-findings', [MobileSafetyManagementController::class, 'storeInspectionFinding'])
            ->middleware('authorize:safety-management.violations.create')
            ->name('inspection_findings.store');
    });
