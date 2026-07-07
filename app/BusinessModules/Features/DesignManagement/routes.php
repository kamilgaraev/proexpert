<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignManagementController;
use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignDocumentationController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/packages', [DesignManagementController::class, 'packages'])
            ->middleware('authorize:design-management.view')
            ->name('packages.index');
        Route::post('/packages', [DesignManagementController::class, 'storePackage'])
            ->middleware('authorize:design-management.create')
            ->name('packages.store');
        Route::get('/packages/{packageId}', [DesignManagementController::class, 'showPackage'])
            ->middleware('authorize:design-management.view')
            ->name('packages.show');
        Route::post('/packages/{packageId}/workflow', [DesignManagementController::class, 'transitionPackageWorkflow'])
            ->middleware('authorize:design-management.view')
            ->name('packages.workflow');
        Route::get('/normative-sources', [DesignDocumentationController::class, 'normativeSources'])
            ->middleware('authorize:design-management.normative_catalog.view')
            ->name('normative_sources.index');
        Route::get('/document-templates', [DesignDocumentationController::class, 'documentTemplates'])
            ->middleware('authorize:design-management.normative_catalog.view')
            ->name('document_templates.index');
        Route::get('/packages/{packageId}/sections', [DesignDocumentationController::class, 'sections'])
            ->middleware('authorize:design-management.documents.view')
            ->name('packages.sections.index');
        Route::post('/packages/{packageId}/sections/generate', [DesignDocumentationController::class, 'generateSections'])
            ->middleware('authorize:design-management.documents.manage_structure')
            ->name('packages.sections.generate');
        Route::post('/packages/{packageId}/sections/custom', [DesignDocumentationController::class, 'storeCustomSectionDocument'])
            ->middleware('authorize:design-management.documents.manage_structure')
            ->name('packages.sections.custom_store');
        Route::post('/packages/{packageId}/sections/{sectionId}/documents', [DesignDocumentationController::class, 'uploadDocument'])
            ->middleware('authorize:design-management.documents.upload')
            ->name('packages.sections.documents.upload');
        Route::put('/document-versions/{versionId}/sheets', [DesignDocumentationController::class, 'replaceSheets'])
            ->middleware('authorize:design-management.documents.edit')
            ->name('document_versions.sheets.replace');
        Route::get('/document-versions/{versionId}/source-file', [DesignDocumentationController::class, 'downloadDocumentSourceFile'])
            ->middleware('authorize:design-management.documents.view')
            ->name('document_versions.source_file');
        Route::post('/packages/{packageId}/completeness-checks', [DesignDocumentationController::class, 'runCompletenessCheck'])
            ->middleware('authorize:design-management.norm_control.run')
            ->name('packages.completeness_checks.store');
        Route::get('/packages/{packageId}/review-comments', [DesignDocumentationController::class, 'reviewComments'])
            ->middleware('authorize:design-management.review')
            ->name('packages.review_comments.index');
        Route::post('/packages/{packageId}/review-comments', [DesignDocumentationController::class, 'storeReviewComment'])
            ->middleware('authorize:design-management.review')
            ->name('packages.review_comments.store');
        Route::patch('/review-comments/{commentId}', [DesignDocumentationController::class, 'updateReviewComment'])
            ->middleware('authorize:design-management.review')
            ->name('review_comments.update');
        Route::get('/packages/{packageId}/issue-register', [DesignDocumentationController::class, 'issueRegister'])
            ->middleware('authorize:design-management.export')
            ->name('packages.issue_register');
        Route::post('/packages/{packageId}/models', [DesignManagementController::class, 'uploadModel'])
            ->middleware('authorize:design-management.models.upload')
            ->name('models.upload');
        Route::post('/packages/{packageId}/models/multipart/start', [DesignManagementController::class, 'startMultipartUpload'])
            ->middleware('authorize:design-management.models.upload')
            ->name('models.multipart.start');
        Route::post('/model-uploads/{uploadId}/parts/{partNumber}', [DesignManagementController::class, 'uploadMultipartPart'])
            ->middleware('authorize:design-management.models.upload')
            ->name('model_uploads.parts.store');
        Route::post('/model-uploads/{uploadId}/complete', [DesignManagementController::class, 'completeMultipartUpload'])
            ->middleware('authorize:design-management.models.upload')
            ->name('model_uploads.complete');
        Route::delete('/model-uploads/{uploadId}', [DesignManagementController::class, 'abortMultipartUpload'])
            ->middleware('authorize:design-management.models.upload')
            ->name('model_uploads.abort');
        Route::post('/model-versions/{versionId}/derivatives', [DesignManagementController::class, 'storeDerivative'])
            ->middleware('authorize:design-management.models.prepare_viewer')
            ->name('model_versions.derivatives.store');
        Route::post('/model-versions/{versionId}/viewer/preparation', [DesignManagementController::class, 'prepareViewer'])
            ->middleware('authorize:design-management.models.prepare_viewer')
            ->name('model_versions.viewer.prepare');
        Route::get('/model-versions/{versionId}/viewer', [DesignManagementController::class, 'viewerPayload'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.viewer');
        Route::get('/model-versions/{versionId}/source-file', [DesignManagementController::class, 'downloadSourceFile'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.source_file');
        Route::get('/model-versions/{versionId}/derivative-file', [DesignManagementController::class, 'downloadDerivativeFile'])
            ->middleware('authorize:design-management.models.view')
            ->name('model_versions.derivative_file');
        Route::post('/model-versions/{versionId}/mark-current', [DesignManagementController::class, 'markCurrent'])
            ->middleware('authorize:design-management.edit')
            ->name('model_versions.mark_current');
    });
