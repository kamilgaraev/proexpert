<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LegalArchiveController;
use App\Http\Controllers\Api\V1\Admin\LegalDocumentEditorController;
use Illuminate\Support\Facades\Route;

Route::prefix('legal-archive')->name('legal-archive.')->group(function (): void {
    Route::post('editor/callback/{session}', [LegalDocumentEditorController::class, 'callback'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
        ->whereUuid('session')->name('editor.callback');

    Route::get('dictionaries', [LegalArchiveController::class, 'dictionaries'])
        ->middleware('authorize:legal_archive.view')
        ->name('dictionaries');

    Route::get('documents', [LegalArchiveController::class, 'index'])
        ->name('documents.index');

    Route::post('documents', [LegalArchiveController::class, 'store'])
        ->middleware('authorize:legal_archive.create')
        ->name('documents.store');

    Route::get('document-recoveries', [LegalArchiveController::class, 'recoveryIndex'])
        ->middleware('authorize:legal_archive.create')
        ->name('document-recoveries.index');

    Route::post('document-recoveries/{operation}', [LegalArchiveController::class, 'recoverCreate'])
        ->middleware('authorize:legal_archive.create')
        ->whereUuid('operation')
        ->name('document-recoveries.recover');

    Route::get('documents/{document}', [LegalArchiveController::class, 'show'])
        ->name('documents.show');

    Route::patch('documents/{document}', [LegalArchiveController::class, 'update'])
        ->middleware('authorize:legal_archive.update')
        ->name('documents.update');

    Route::post('documents/{document}/management-recovery', [LegalArchiveController::class, 'recoverManagement'])
        ->middleware('authorize:legal_archive.security_recovery.manage')
        ->name('documents.management-recovery');

    Route::post('documents/{document}/versions', [LegalArchiveController::class, 'storeVersion'])
        ->middleware(['authorize:legal_archive.versions.create', 'authorize:legal_archive.files.upload'])
        ->name('documents.versions.store');

    Route::get('documents/{document}/current-version', [LegalArchiveController::class, 'currentVersion'])
        ->name('documents.current-version');

    Route::post('documents/{document}/versions/{version}/editor', [LegalDocumentEditorController::class, 'open'])
        ->middleware('authorize:legal_archive.editor.edit')->name('documents.versions.editor');
    Route::get('documents/{document}/versions/{version}/preview', [LegalDocumentEditorController::class, 'preview'])
        ->middleware('authorize:legal_archive.view')->name('documents.versions.preview');
    Route::get('documents/{document}/versions/{version}/download', [LegalDocumentEditorController::class, 'download'])
        ->middleware('authorize:legal_archive.files.download')->name('documents.versions.download');
});
