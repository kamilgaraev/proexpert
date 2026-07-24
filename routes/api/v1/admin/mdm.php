<?php

declare(strict_types=1);

use App\BusinessModules\Core\Mdm\Http\Controllers\MdmChangeRequestsController;
use App\BusinessModules\Core\Mdm\Http\Controllers\MdmDuplicatesController;
use App\BusinessModules\Core\Mdm\Http\Controllers\MdmImportsController;
use App\BusinessModules\Core\Mdm\Http\Controllers\MdmQualityPoliciesController;
use App\BusinessModules\Core\Mdm\Http\Controllers\MdmRecordsController;
use Illuminate\Support\Facades\Route;

Route::prefix('mdm')
    ->name('mdm.')
    ->group(function (): void {
        Route::middleware('authorize:mdm.view')->group(function (): void {
            Route::get('/entities', [MdmRecordsController::class, 'entities'])->name('entities');
            Route::get('/dashboard', [MdmRecordsController::class, 'dashboard'])->name('dashboard');
            Route::get('/records', [MdmRecordsController::class, 'records'])->name('records');
            Route::get('/records/{mdmRecord}', [MdmRecordsController::class, 'record'])->name('records.show');
            Route::get('/duplicates', [MdmDuplicatesController::class, 'duplicates'])->name('duplicates');
            Route::get('/relationships', [MdmRecordsController::class, 'relationships'])->name('relationships');
            Route::get('/history', [MdmRecordsController::class, 'history'])->name('history');
            Route::get('/change-requests', [MdmChangeRequestsController::class, 'changeRequests'])->middleware('authorize:mdm.change_requests.view')->name('change_requests');
            Route::get('/change-requests/{mdmChangeRequest}', [MdmChangeRequestsController::class, 'changeRequest'])->middleware('authorize:mdm.change_requests.view')->name('change_requests.show');
            Route::get('/change-requests/{mdmChangeRequest}/timeline', [MdmChangeRequestsController::class, 'changeRequestTimeline'])->middleware('authorize:mdm.change_requests.view')->name('change_requests.timeline');
            Route::get('/change-requests/{mdmChangeRequest}/impact', [MdmChangeRequestsController::class, 'changeRequestImpact'])->middleware('authorize:mdm.impact.view')->name('change_requests.impact');
            Route::get('/quality-policies', [MdmQualityPoliciesController::class, 'qualityPolicies'])->name('quality_policies');
        });

        Route::post('/sync', [MdmRecordsController::class, 'sync'])->middleware('authorize:mdm.manage')->name('sync');
        Route::post('/duplicates/scan', [MdmDuplicatesController::class, 'scanDuplicates'])->middleware('authorize:mdm.manage')->name('duplicates.scan');
        Route::post('/duplicates/{duplicateGroup}/resolve', [MdmDuplicatesController::class, 'resolveDuplicate'])->middleware('authorize:mdm.duplicates.resolve')->name('duplicates.resolve');
        Route::post('/duplicates/{duplicateGroup}/merge-plan', [MdmDuplicatesController::class, 'mergePlan'])->middleware('authorize:mdm.merge.apply')->name('duplicates.merge_plan');
        Route::post('/duplicates/{duplicateGroup}/merge-apply', [MdmDuplicatesController::class, 'mergeApply'])->middleware('authorize:mdm.merge.apply')->name('duplicates.merge_apply');
        Route::post('/records/{entityType}/{entityId}/archive', [MdmRecordsController::class, 'archive'])->middleware('authorize:mdm.archive')->name('records.archive');
        Route::post('/records/{mdmRecord}/owner', [MdmRecordsController::class, 'assignOwner'])->middleware('authorize:mdm.owners.assign')->name('records.owner');
        Route::post('/relationships/sync', [MdmRecordsController::class, 'syncRelationships'])->middleware('authorize:mdm.manage')->name('relationships.sync');
        Route::post('/imports/preview', [MdmImportsController::class, 'importPreview'])->middleware('authorize:mdm.import.preview')->name('imports.preview');
        Route::post('/imports/apply', [MdmImportsController::class, 'importApply'])->middleware('authorize:mdm.import.apply')->name('imports.apply');
        Route::post('/imports/file/preview', [MdmImportsController::class, 'fileImportPreview'])->middleware('authorize:mdm.import.preview')->name('imports.file.preview');
        Route::post('/imports/file/apply', [MdmImportsController::class, 'fileImportApply'])->middleware('authorize:mdm.import.apply')->name('imports.file.apply');
        Route::post('/change-requests/preview', [MdmChangeRequestsController::class, 'previewChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.preview');
        Route::post('/change-requests', [MdmChangeRequestsController::class, 'submitChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.create');
        Route::patch('/change-requests/{mdmChangeRequest}', [MdmChangeRequestsController::class, 'updateChangeRequest'])->middleware('authorize:mdm.change_requests.create')->name('change_requests.update');
        Route::post('/change-requests/{mdmChangeRequest}/submit', [MdmChangeRequestsController::class, 'submitDraftChangeRequest'])->middleware('authorize:mdm.change_requests.submit')->name('change_requests.submit');
        Route::post('/change-requests/{mdmChangeRequest}/start-review', [MdmChangeRequestsController::class, 'startReviewChangeRequest'])->middleware('authorize:mdm.change_requests.review')->name('change_requests.start_review');
        Route::post('/change-requests/{mdmChangeRequest}/approve', [MdmChangeRequestsController::class, 'approveChangeRequest'])->middleware('authorize:mdm.change_requests.approve')->name('change_requests.approve');
        Route::post('/change-requests/{mdmChangeRequest}/reject', [MdmChangeRequestsController::class, 'rejectChangeRequest'])->middleware('authorize:mdm.change_requests.reject')->name('change_requests.reject');
        Route::post('/change-requests/{mdmChangeRequest}/apply', [MdmChangeRequestsController::class, 'applyChangeRequest'])->middleware('authorize:mdm.change_requests.apply')->name('change_requests.apply');
        Route::post('/change-requests/{mdmChangeRequest}/cancel', [MdmChangeRequestsController::class, 'cancelChangeRequest'])->middleware('authorize:mdm.change_requests.cancel')->name('change_requests.cancel');
        Route::post('/change-requests/{mdmChangeRequest}/review', [MdmChangeRequestsController::class, 'reviewChangeRequest'])->middleware('authorize:mdm.change_requests.review')->name('change_requests.review');
        Route::put('/quality-policies/{entityType}', [MdmQualityPoliciesController::class, 'updateQualityPolicy'])->middleware('authorize:mdm.records.field_policy.manage')->name('quality_policies.update');
    });
