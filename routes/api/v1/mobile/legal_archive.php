<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\LegalArchiveController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function (): void {
    Route::get('/legal-archive/documents', [LegalArchiveController::class, 'index'])->name('legal-archive.documents.index');
    Route::get('/legal-archive/documents/{document}', [LegalArchiveController::class, 'show'])->whereNumber('document')->name('legal-archive.documents.show');
    Route::post('/legal-archive/documents/{document}/actions/{action}', [LegalArchiveController::class, 'action'])->whereNumber('document')->whereIn('action', ['approve', 'reject', 'return'])->name('legal-archive.documents.action');
    Route::get('/legal-archive/documents/{document}/versions/{version}/{purpose}', [LegalArchiveController::class, 'versionUrl'])->whereNumber('document')->whereNumber('version')->whereIn('purpose', ['preview', 'download'])->name('legal-archive.versions.url');
    Route::post('/legal-archive/signature-requests/{signatureRequest}/upload-original', [LegalArchiveController::class, 'uploadOriginal'])->whereNumber('signatureRequest')->name('legal-archive.signatures.upload-original');
});
