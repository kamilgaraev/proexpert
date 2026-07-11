<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationActionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationReviewController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Http\Controllers\EstimateNormativeStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'api',
    'auth:api_admin',
    'auth.jwt:api_admin',
    'organization.context',
    'interface:admin',
    'project.context',
])
    ->prefix('api/v1/admin/projects/{project}/estimate-generation/sessions')
    ->name('api.v1.admin.projects.estimate-generation.sessions.')
    ->group(function (): void {
        Route::get('/', [EstimateGenerationSessionController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('index');
        Route::post('/', [EstimateGenerationSessionController::class, 'store'])->middleware('authorize:estimate_generation.create,project,project')->name('store');
        Route::get('/{session}/documents', [EstimateGenerationDocumentController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.index');
        Route::post('/{session}/documents', [EstimateGenerationDocumentController::class, 'upload'])->middleware('authorize:estimate_generation.upload_documents,project,project')->name('documents.store');
        Route::get('/{session}/documents/{document}', [EstimateGenerationDocumentController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.show');
        Route::post('/{session}/documents/{document}/retry', [EstimateGenerationDocumentController::class, 'retry'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.retry');
        Route::post('/{session}/documents/{document}/ignore', [EstimateGenerationDocumentController::class, 'ignore'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.ignore');
        Route::post('/{session}/analyze', [EstimateGenerationActionController::class, 'analyze'])->middleware('authorize:estimate_generation.generate,project,project')->name('analyze');
        Route::post('/{session}/generate', [EstimateGenerationActionController::class, 'generate'])->middleware('authorize:estimate_generation.generate,project,project')->name('generate');
        Route::post('/{session}/confirm-input', [EstimateGenerationActionController::class, 'confirmInput'])->middleware('authorize:estimate_generation.review,project,project')->name('confirm-input');
        Route::post('/{session}/retry', [EstimateGenerationActionController::class, 'retry'])->middleware('authorize:estimate_generation.generate,project,project')->name('retry');
        Route::post('/{session}/cancel', [EstimateGenerationActionController::class, 'cancel'])->middleware('authorize:estimate_generation.generate,project,project')->name('cancel');
        Route::post('/{session}/archive', [EstimateGenerationActionController::class, 'archive'])->middleware('authorize:estimate_generation.generate,project,project')->name('archive');
        Route::get('/{session}/status', [EstimateGenerationSessionController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('status');
        Route::get('/{session}/snapshot', [EstimateGenerationSessionController::class, 'snapshot'])->middleware('authorize:estimate_generation.view,project,project')->name('snapshot');
        Route::get('/{session}/packages', [EstimateGenerationPackageController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.index');
        Route::get('/{session}/packages/{package}', [EstimateGenerationPackageController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.show');
        Route::get('/{session}/draft', [EstimateGenerationPackageController::class, 'draft'])->middleware('authorize:estimate_generation.view,project,project')->name('draft');
        Route::get('/{session}/review-items', [EstimateGenerationReviewController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('review-items');
        Route::get('/{session}', [EstimateGenerationSessionController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('show');
        Route::get('/{session}/export', [EstimateGenerationPackageController::class, 'export'])->middleware('authorize:estimate_generation.export,project,project')->name('export');
        Route::post('/{session}/apply', [EstimateGenerationActionController::class, 'apply'])->middleware('authorize:estimate_generation.apply,project,project')->name('apply');
        Route::get('/{session}/normative-candidates/search', [EstimateGenerationReviewController::class, 'search'])->middleware('authorize:estimate_generation.select_normative,project,project')->name('normative-candidates.search');
        Route::post('/{session}/normative-candidate', [EstimateGenerationReviewController::class, 'select'])->middleware('authorize:estimate_generation.select_normative,project,project')->name('normative-candidate.select');
        Route::post('/{session}/rebuild-section', [EstimateGenerationActionController::class, 'rebuildSection'])->middleware('authorize:estimate_generation.generate,project,project')->name('rebuild-section');
        Route::post('/{session}/feedback', [EstimateGenerationReviewController::class, 'feedback'])->middleware('authorize:estimate_generation.review,project,project')->name('feedback');
    });

Route::middleware([
    'api',
    'auth:api_admin',
    'auth.jwt:api_admin',
    'organization.context',
    'interface:admin',
])
    ->prefix('api/v1/admin/estimate-generation/normatives')
    ->name('api.v1.admin.estimate-generation.normatives.')
    ->group(function (): void {
        Route::get('/statuses', [EstimateNormativeStatusController::class, 'index'])->middleware('authorize:estimate_generation.select_normative,organization,current_organization_id')->name('statuses.index');
    });
