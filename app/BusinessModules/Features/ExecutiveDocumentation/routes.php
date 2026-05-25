<?php

declare(strict_types=1);

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\Customer\ExecutiveDocumentationController as CustomerExecutiveDocumentationController;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\ExecutiveDocumentationController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/executive-documentation')
    ->name('admin.executive_documentation.')
    ->middleware(AdminRouteStack::middleware(['executive-documentation.active']))
    ->group(function (): void {
        Route::get('/sets', [ExecutiveDocumentationController::class, 'index'])
            ->middleware('authorize:executive-documentation.view')
            ->name('sets.index');
        Route::get('/references', [ExecutiveDocumentationController::class, 'references'])
            ->middleware('authorize:executive-documentation.view')
            ->name('references.index');
        Route::post('/sets', [ExecutiveDocumentationController::class, 'storeSet'])
            ->middleware('authorize:executive-documentation.create')
            ->name('sets.store');
        Route::get('/sets/{id}', [ExecutiveDocumentationController::class, 'showSet'])
            ->middleware('authorize:executive-documentation.view')
            ->name('sets.show');
        Route::post('/sets/{id}/documents', [ExecutiveDocumentationController::class, 'storeDocument'])
            ->middleware('authorize:executive-documentation.create')
            ->name('documents.store');
        Route::post('/sets/{id}/transmit', [ExecutiveDocumentationController::class, 'transmit'])
            ->middleware('authorize:executive-documentation.approve')
            ->name('sets.transmit');
        Route::post('/documents/{id}/submit', [ExecutiveDocumentationController::class, 'submit'])
            ->middleware('authorize:executive-documentation.submit')
            ->name('documents.submit');
        Route::post('/documents/{id}/remarks', [ExecutiveDocumentationController::class, 'storeRemark'])
            ->middleware('authorize:executive-documentation.review')
            ->name('remarks.store');
        Route::post('/documents/{id}/approve', [ExecutiveDocumentationController::class, 'approve'])
            ->middleware('authorize:executive-documentation.approve')
            ->name('documents.approve');
        Route::post('/remarks/{id}/resolve', [ExecutiveDocumentationController::class, 'resolveRemark'])
            ->middleware('authorize:executive-documentation.edit')
            ->name('remarks.resolve');
        Route::delete('/documents/{documentId}/versions/{versionId}', [ExecutiveDocumentationController::class, 'deleteVersion'])
            ->middleware('authorize:executive-documentation.delete')
            ->name('versions.delete');
    });

Route::prefix('api/v1/customer/executive-documentation')
    ->name('customer.executive_documentation.')
    ->middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context', 'executive-documentation.active'])
    ->group(function (): void {
        Route::get('/sets', [CustomerExecutiveDocumentationController::class, 'index'])
            ->middleware('authorize:executive-documentation.view')
            ->name('sets.index');
        Route::get('/sets/{id}', [CustomerExecutiveDocumentationController::class, 'show'])
            ->middleware('authorize:executive-documentation.view')
            ->name('sets.show');
        Route::post('/sets/{id}/acknowledge', [CustomerExecutiveDocumentationController::class, 'acknowledge'])
            ->middleware('authorize:executive-documentation.approve')
            ->name('sets.acknowledge');
        Route::post('/documents/{id}/remarks', [CustomerExecutiveDocumentationController::class, 'storeRemark'])
            ->middleware('authorize:executive-documentation.review')
            ->name('remarks.store');
    });
