<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveAccessController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveDocumentController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveFileController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveRetentionController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveSettingsController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveSignatureController;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveWorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('legal-archive')->name('legal-archive.')->group(function (): void {
    Route::get('dictionaries', [LegalArchiveSettingsController::class, 'dictionaries'])
        ->middleware('authorize:legal_archive.view')->name('dictionaries');

    Route::get('type-profiles', [LegalArchiveSettingsController::class, 'typeProfiles'])
        ->middleware('authorize:legal_archive.view')->name('type-profiles.index');
    Route::post('type-profiles', [LegalArchiveSettingsController::class, 'storeTypeProfile'])
        ->middleware('authorize:legal_archive.settings.manage')->name('type-profiles.store');
    Route::patch('type-profiles/{profile}', [LegalArchiveSettingsController::class, 'updateTypeProfile'])
        ->middleware('authorize:legal_archive.settings.manage')->whereUuid('profile')->name('type-profiles.update');
    Route::get('workflow-templates', [LegalArchiveSettingsController::class, 'workflowTemplates'])
        ->middleware('authorize:legal_archive.view')->name('workflow-templates.index');
    Route::post('workflow-templates', [LegalArchiveSettingsController::class, 'storeWorkflowTemplate'])
        ->middleware('authorize:legal_archive.settings.manage')->name('workflow-templates.store');
    Route::post('workflow-templates/{template}/versions', [LegalArchiveSettingsController::class, 'createWorkflowTemplateVersion'])
        ->middleware('authorize:legal_archive.settings.manage')->whereNumber('template')->name('workflow-templates.versions.store');

    Route::get('documents', [LegalArchiveDocumentController::class, 'index'])
        ->name('documents.index');
    Route::post('documents', [LegalArchiveDocumentController::class, 'store'])
        ->middleware('authorize:legal_archive.create')->name('documents.store');
    Route::get('documents/{document}', [LegalArchiveDocumentController::class, 'show'])
        ->whereNumber('document')->name('documents.show');
    Route::patch('documents/{document}', [LegalArchiveDocumentController::class, 'update'])
        ->middleware('authorize:legal_archive.update')->whereNumber('document')->name('documents.update');
    Route::post('documents/{document}/archive', [LegalArchiveDocumentController::class, 'archive'])
        ->middleware('authorize:legal_archive.archive')->whereNumber('document')->name('documents.archive');
    Route::post('documents/{document}/restore', [LegalArchiveDocumentController::class, 'restore'])
        ->middleware('authorize:legal_archive.archive')->whereNumber('document')->name('documents.restore');
    Route::post('documents/{document}/activate', [LegalArchiveDocumentController::class, 'activate'])
        ->middleware('authorize:legal_archive.signatures.verify')->whereNumber('document')->name('documents.activate');
    Route::get('documents/{document}/timeline', [LegalArchiveDocumentController::class, 'timeline'])
        ->middleware('authorize:legal_archive.audit.view')->whereNumber('document')->name('documents.timeline');
    Route::get('documents/{document}/available-actions', [LegalArchiveDocumentController::class, 'availableActions'])
        ->middleware('authorize:legal_archive.view')->whereNumber('document')->name('documents.available-actions');

    Route::post('documents/{document}/files', [LegalArchiveFileController::class, 'storeFile'])
        ->middleware(['authorize:legal_archive.files.upload', 'authorize:legal_archive.versions.create'])
        ->whereNumber('document')->name('documents.files.store');
    Route::post('document-files/{file}/versions', [LegalArchiveFileController::class, 'storeVersion'])
        ->middleware(['authorize:legal_archive.files.upload', 'authorize:legal_archive.versions.create'])
        ->whereNumber('file')->name('document-files.versions.store');
    Route::get('document-file-versions/{version}/preview', [LegalArchiveFileController::class, 'preview'])
        ->middleware('authorize:legal_archive.files.view')->whereNumber('version')->name('document-file-versions.preview');
    Route::get('document-file-versions/{version}/download', [LegalArchiveFileController::class, 'download'])
        ->middleware('authorize:legal_archive.files.download')->whereNumber('version')->name('document-file-versions.download');
    Route::post('document-file-versions/{version}/make-current', [LegalArchiveFileController::class, 'makeCurrent'])
        ->middleware('authorize:legal_archive.versions.manage')->whereNumber('version')->name('document-file-versions.make-current');
    Route::get('document-file-versions/{version}/compare/{other}', [LegalArchiveFileController::class, 'compare'])
        ->middleware('authorize:legal_archive.files.view')->whereNumber('version')->whereNumber('other')->name('document-file-versions.compare');
    Route::post('document-file-versions/{version}/editor/session', [LegalArchiveFileController::class, 'editorSession'])
        ->middleware('authorize:legal_archive.view')->whereNumber('version')->name('document-file-versions.editor.session');

    Route::post('documents/{document}/workflow/submit', [LegalArchiveWorkflowController::class, 'submit'])
        ->middleware('authorize:legal_archive.workflow.submit')->whereNumber('document')->name('workflow.submit');
    Route::post('workflow-steps/{step}/approve', [LegalArchiveWorkflowController::class, 'approve'])
        ->middleware('authorize:legal_archive.workflow.approve')->whereNumber('step')->name('workflow.approve');
    Route::post('workflow-steps/{step}/reject', [LegalArchiveWorkflowController::class, 'reject'])
        ->middleware('authorize:legal_archive.workflow.reject')->whereNumber('step')->name('workflow.reject');
    Route::post('workflow-steps/{step}/return', [LegalArchiveWorkflowController::class, 'returnForRevision'])
        ->middleware('authorize:legal_archive.workflow.return')->whereNumber('step')->name('workflow.return');
    Route::post('workflow-steps/{step}/reassign', [LegalArchiveWorkflowController::class, 'reassign'])
        ->middleware('authorize:legal_archive.workflow.reassign')->whereNumber('step')->name('workflow.reassign');
    Route::post('workflow-instances/{instance}/cancel', [LegalArchiveWorkflowController::class, 'cancel'])
        ->middleware('authorize:legal_archive.workflow.cancel')->whereNumber('instance')->name('workflow.cancel');

    Route::post('documents/{document}/signatures/requests', [LegalArchiveSignatureController::class, 'createRequests'])
        ->middleware('authorize:legal_archive.signatures.request')->whereNumber('document')->name('signatures.requests.store');
    Route::post('signature-requests/{signatureRequest}/upload-original', [LegalArchiveSignatureController::class, 'uploadOriginal'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.upload-original');
    Route::post('signature-requests/{signatureRequest}/signing-session', [LegalArchiveSignatureController::class, 'signingSession'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.signing-session');
    Route::post('signature-requests/{signatureRequest}/complete', [LegalArchiveSignatureController::class, 'complete'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.complete');
    Route::get('document-file-versions/{version}/signatures', [LegalArchiveSignatureController::class, 'index'])
        ->middleware('authorize:legal_archive.files.view')->whereNumber('version')->name('signatures.index');
    Route::post('signatures/{signature}/verify', [LegalArchiveSignatureController::class, 'verify'])
        ->middleware('authorize:legal_archive.signatures.verify')->whereNumber('signature')->name('signatures.verify');

    Route::get('documents/{document}/access', [LegalArchiveAccessController::class, 'index'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('document')->name('access.index');
    Route::post('documents/{document}/access', [LegalArchiveAccessController::class, 'store'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('document')->name('access.store');
    Route::post('access-grants/{grant}/revoke', [LegalArchiveAccessController::class, 'revoke'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('grant')->name('access.revoke');
    Route::post('documents/{document}/management-recovery', [LegalArchiveAccessController::class, 'recoverManagement'])
        ->middleware('authorize:legal_archive.security_recovery.manage')->whereNumber('document')->name('access.management-recovery');
    Route::patch('documents/{document}/retention', [LegalArchiveRetentionController::class, 'update'])
        ->middleware('authorize:legal_archive.retention.manage')->whereNumber('document')->name('retention.update');
    Route::post('documents/{document}/legal-hold', [LegalArchiveRetentionController::class, 'legalHold'])
        ->middleware('authorize:legal_archive.legal_hold.manage')->whereNumber('document')->name('legal-hold.update');

    Route::get('document-recoveries', [LegalArchiveDocumentController::class, 'recoveryIndex'])
        ->middleware('authorize:legal_archive.create')->name('document-recoveries.index');
    Route::post('document-recoveries/{operation}', [LegalArchiveDocumentController::class, 'recoverCreate'])
        ->middleware('authorize:legal_archive.create')->whereUuid('operation')->name('document-recoveries.recover');

    Route::post('documents/{document}/versions', [LegalArchiveFileController::class, 'storePrimaryVersion'])
        ->middleware(['authorize:legal_archive.versions.create', 'authorize:legal_archive.files.upload'])
        ->whereNumber('document')->name('documents.versions.store');
    Route::get('documents/{document}/current-version', [LegalArchiveFileController::class, 'currentPrimaryVersion'])
        ->whereNumber('document')->name('documents.current-version');
});
