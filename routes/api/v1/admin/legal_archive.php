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
    Route::get('type-profiles/{documentTypeProfile}', [LegalArchiveSettingsController::class, 'showTypeProfile'])
        ->middleware('authorize:legal_archive.view')->where('documentTypeProfile', '[A-Za-z0-9._-]+')->name('type-profiles.show');
    Route::patch('type-profiles/{documentTypeProfile}', [LegalArchiveSettingsController::class, 'updateTypeProfile'])
        ->middleware('authorize:legal_archive.settings.manage')->whereUuid('documentTypeProfile')->name('type-profiles.update');
    Route::get('workflow-templates', [LegalArchiveSettingsController::class, 'workflowTemplates'])
        ->middleware('authorize:legal_archive.view')->name('workflow-templates.index');
    Route::post('workflow-templates', [LegalArchiveSettingsController::class, 'storeWorkflowTemplate'])
        ->middleware('authorize:legal_archive.settings.manage')->name('workflow-templates.store');
    Route::get('workflow-templates/{legalWorkflowTemplate}', [LegalArchiveSettingsController::class, 'showWorkflowTemplate'])
        ->middleware('authorize:legal_archive.view')->whereNumber('legalWorkflowTemplate')->name('workflow-templates.show');
    Route::post('workflow-templates/{legalWorkflowTemplate}/versions', [LegalArchiveSettingsController::class, 'createWorkflowTemplateVersion'])
        ->middleware('authorize:legal_archive.settings.manage')->whereNumber('legalWorkflowTemplate')->name('workflow-templates.versions.store');

    Route::get('documents', [LegalArchiveDocumentController::class, 'index'])
        ->name('documents.index');
    Route::post('documents', [LegalArchiveDocumentController::class, 'store'])
        ->middleware('authorize:legal_archive.create')->name('documents.store');
    Route::get('documents/{legalDocument}', [LegalArchiveDocumentController::class, 'show'])
        ->whereNumber('legalDocument')->name('documents.show');
    Route::patch('documents/{legalDocument}', [LegalArchiveDocumentController::class, 'update'])
        ->middleware('authorize:legal_archive.update')->whereNumber('legalDocument')->name('documents.update');
    Route::post('documents/{legalDocument}/archive', [LegalArchiveDocumentController::class, 'archive'])
        ->middleware('authorize:legal_archive.archive')->whereNumber('legalDocument')->name('documents.archive');
    Route::post('documents/{legalDocument}/restore', [LegalArchiveDocumentController::class, 'restore'])
        ->middleware('authorize:legal_archive.archive')->whereNumber('legalDocument')->name('documents.restore');
    Route::post('documents/{legalDocument}/activate', [LegalArchiveDocumentController::class, 'activate'])
        ->middleware('authorize:legal_archive.signatures.verify')->whereNumber('legalDocument')->name('documents.activate');
    Route::patch('documents/{legalDocument}/obligations/{obligation}', [LegalArchiveDocumentController::class, 'updateObligation'])
        ->middleware('authorize:legal_archive.update')->whereNumber('legalDocument')->whereNumber('obligation')->name('documents.obligations.update');
    Route::get('documents/{legalDocument}/timeline', [LegalArchiveDocumentController::class, 'timeline'])
        ->middleware('authorize:legal_archive.audit.view')->whereNumber('legalDocument')->name('documents.timeline');
    Route::get('documents/{legalDocument}/available-actions', [LegalArchiveDocumentController::class, 'availableActions'])
        ->middleware('authorize:legal_archive.workflow.view')->whereNumber('legalDocument')->name('documents.available-actions');

    Route::post('documents/{legalDocument}/files', [LegalArchiveFileController::class, 'storeFile'])
        ->middleware(['authorize:legal_archive.files.upload', 'authorize:legal_archive.versions.create'])
        ->whereNumber('legalDocument')->name('documents.files.store');
    Route::post('document-files/{legalDocumentFile}/versions', [LegalArchiveFileController::class, 'storeVersion'])
        ->middleware(['authorize:legal_archive.files.upload', 'authorize:legal_archive.versions.create'])
        ->whereNumber('legalDocumentFile')->name('document-files.versions.store');
    Route::get('document-file-versions/{documentVersion}/preview', [LegalArchiveFileController::class, 'preview'])
        ->middleware('authorize:legal_archive.files.view')->whereNumber('documentVersion')->name('document-file-versions.preview');
    Route::get('document-file-versions/{documentVersion}/download', [LegalArchiveFileController::class, 'download'])
        ->middleware('authorize:legal_archive.files.download')->whereNumber('documentVersion')->name('document-file-versions.download');
    Route::post('document-file-versions/{documentVersion}/make-current', [LegalArchiveFileController::class, 'makeCurrent'])
        ->middleware('authorize:legal_archive.versions.manage')->whereNumber('documentVersion')->name('document-file-versions.make-current');
    Route::get('document-file-versions/{documentVersion}/compare/{otherDocumentVersion}', [LegalArchiveFileController::class, 'compare'])
        ->middleware('authorize:legal_archive.files.view')->whereNumber('documentVersion')->whereNumber('otherDocumentVersion')->name('document-file-versions.compare');
    Route::post('document-file-versions/{documentVersion}/editor/session', [LegalArchiveFileController::class, 'editorSession'])
        ->middleware('authorize:legal_archive.view')->whereNumber('documentVersion')->name('document-file-versions.editor.session');
    Route::post('documents/{legalDocument}/editor/blank-session', [LegalArchiveFileController::class, 'startBlankEditorSession'])
        ->middleware(['authorize:legal_archive.files.upload', 'authorize:legal_archive.versions.create', 'authorize:legal_archive.editor.edit'])
        ->whereNumber('legalDocument')->name('documents.editor.blank-session');

    Route::post('documents/{legalDocument}/workflow/submit', [LegalArchiveWorkflowController::class, 'submit'])
        ->middleware('authorize:legal_archive.workflow.submit')->whereNumber('legalDocument')->name('workflow.submit');
    Route::post('workflow-steps/{legalWorkflowStep}/approve', [LegalArchiveWorkflowController::class, 'approve'])
        ->middleware('authorize:legal_archive.workflow.approve')->whereNumber('legalWorkflowStep')->name('workflow.approve');
    Route::post('workflow-steps/{legalWorkflowStep}/reject', [LegalArchiveWorkflowController::class, 'reject'])
        ->middleware('authorize:legal_archive.workflow.reject')->whereNumber('legalWorkflowStep')->name('workflow.reject');
    Route::post('workflow-steps/{legalWorkflowStep}/return', [LegalArchiveWorkflowController::class, 'returnForRevision'])
        ->middleware('authorize:legal_archive.workflow.return')->whereNumber('legalWorkflowStep')->name('workflow.return');
    Route::post('workflow-steps/{legalWorkflowStep}/reassign', [LegalArchiveWorkflowController::class, 'reassign'])
        ->middleware('authorize:legal_archive.workflow.reassign')->whereNumber('legalWorkflowStep')->name('workflow.reassign');
    Route::post('workflow-instances/{legalWorkflowInstance}/cancel', [LegalArchiveWorkflowController::class, 'cancel'])
        ->middleware('authorize:legal_archive.workflow.cancel')->whereNumber('legalWorkflowInstance')->name('workflow.cancel');

    Route::post('documents/{legalDocument}/signatures/requests', [LegalArchiveSignatureController::class, 'createRequests'])
        ->middleware('authorize:legal_archive.signatures.request')->whereNumber('legalDocument')->name('signatures.requests.store');
    Route::post('signature-requests/{signatureRequest}/upload-original', [LegalArchiveSignatureController::class, 'uploadOriginal'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.upload-original');
    Route::post('signature-requests/{signatureRequest}/signing-session', [LegalArchiveSignatureController::class, 'signingSession'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.signing-session');
    Route::post('signature-requests/{signatureRequest}/complete', [LegalArchiveSignatureController::class, 'complete'])
        ->middleware('authorize:legal_archive.signatures.sign')->whereNumber('signatureRequest')->name('signatures.complete');
    Route::get('document-file-versions/{documentVersion}/signatures', [LegalArchiveSignatureController::class, 'index'])
        ->middleware('authorize:legal_archive.signatures.view')->whereNumber('documentVersion')->name('signatures.index');
    Route::post('signatures/{legalSignature}/verify', [LegalArchiveSignatureController::class, 'verify'])
        ->middleware('authorize:legal_archive.signatures.verify')->whereNumber('legalSignature')->name('signatures.verify');
    Route::get('signatures/{legalSignature}/verification-history', [LegalArchiveSignatureController::class, 'verificationHistory'])
        ->middleware('authorize:legal_archive.signatures.view')->whereNumber('legalSignature')->name('signatures.verification-history');

    Route::get('documents/{legalDocument}/access', [LegalArchiveAccessController::class, 'index'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('legalDocument')->name('access.index');
    Route::post('documents/{legalDocument}/access', [LegalArchiveAccessController::class, 'store'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('legalDocument')->name('access.store');
    Route::post('access-grants/{legalAccessGrant}/revoke', [LegalArchiveAccessController::class, 'revoke'])
        ->middleware('authorize:legal_archive.external_access.manage')->whereNumber('legalAccessGrant')->name('access.revoke');
    Route::post('documents/{legalDocument}/management-recovery', [LegalArchiveAccessController::class, 'recoverManagement'])
        ->middleware('authorize:legal_archive.security_recovery.manage')->whereNumber('legalDocument')->name('access.management-recovery');
    Route::patch('documents/{legalDocument}/retention', [LegalArchiveRetentionController::class, 'update'])
        ->middleware('authorize:legal_archive.retention.manage')->whereNumber('legalDocument')->name('retention.update');
    Route::post('documents/{legalDocument}/legal-hold', [LegalArchiveRetentionController::class, 'legalHold'])
        ->middleware('authorize:legal_archive.legal_hold.manage')->whereNumber('legalDocument')->name('legal-hold.update');

    Route::get('document-recoveries', [LegalArchiveDocumentController::class, 'recoveryIndex'])
        ->middleware('authorize:legal_archive.create')->name('document-recoveries.index');
    Route::post('document-recoveries/{operation}', [LegalArchiveDocumentController::class, 'recoverCreate'])
        ->middleware('authorize:legal_archive.create')->whereUuid('operation')->name('document-recoveries.recover');

    Route::post('documents/{legalDocument}/versions', [LegalArchiveFileController::class, 'storePrimaryVersion'])
        ->middleware(['authorize:legal_archive.versions.create', 'authorize:legal_archive.files.upload'])
        ->whereNumber('legalDocument')->name('documents.versions.store');
    Route::get('documents/{legalDocument}/current-version', [LegalArchiveFileController::class, 'currentPrimaryVersion'])
        ->whereNumber('legalDocument')->name('documents.current-version');
});
