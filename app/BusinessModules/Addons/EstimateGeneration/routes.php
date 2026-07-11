<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
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
        Route::get('/', [EstimateGenerationController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('index');
        Route::post('/', [EstimateGenerationController::class, 'store'])->middleware('authorize:estimate_generation.create,project,project')->name('store');
        Route::get('/{session}/documents', [EstimateGenerationDocumentController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.index');
        Route::post('/{session}/documents', [EstimateGenerationController::class, 'uploadDocuments'])->middleware('authorize:estimate_generation.upload_documents,project,project')->name('documents.store');
        Route::get('/{session}/documents/{document}', [EstimateGenerationDocumentController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.show');
        Route::post('/{session}/documents/{document}/retry', [EstimateGenerationDocumentController::class, 'retry'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.retry');
        Route::post('/{session}/documents/{document}/ignore', [EstimateGenerationDocumentController::class, 'ignore'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.ignore');
        Route::post('/{session}/analyze', [EstimateGenerationController::class, 'analyze'])->middleware('authorize:estimate_generation.generate,project,project')->name('analyze');
        Route::post('/{session}/generate', [EstimateGenerationController::class, 'generate'])->middleware('authorize:estimate_generation.generate,project,project')->name('generate');
        Route::post('/{session}/confirm-input', [EstimateGenerationController::class, 'confirmInput'])->middleware('authorize:estimate_generation.review,project,project')->name('confirm-input');
        Route::post('/{session}/retry', [EstimateGenerationController::class, 'retry'])->middleware('authorize:estimate_generation.generate,project,project')->name('retry');
        Route::post('/{session}/cancel', [EstimateGenerationController::class, 'cancel'])->middleware('authorize:estimate_generation.generate,project,project')->name('cancel');
        Route::post('/{session}/archive', [EstimateGenerationController::class, 'archive'])->middleware('authorize:estimate_generation.generate,project,project')->name('archive');
        Route::get('/{session}/status', [EstimateGenerationController::class, 'status'])->middleware('authorize:estimate_generation.view,project,project')->name('status');
        Route::get('/{session}/packages', [EstimateGenerationController::class, 'packages'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.index');
        Route::get('/{session}/packages/{package}', [EstimateGenerationController::class, 'package'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.show');
        Route::get('/{session}/draft', [EstimateGenerationController::class, 'draft'])->middleware('authorize:estimate_generation.view,project,project')->name('draft');
        Route::get('/{session}/review-items', [EstimateGenerationController::class, 'reviewItems'])->middleware('authorize:estimate_generation.view,project,project')->name('review-items');
        Route::get('/{session}', [EstimateGenerationController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('show');
        Route::get('/{session}/export', [EstimateGenerationController::class, 'export'])->middleware('authorize:estimate_generation.export,project,project')->name('export');
        Route::post('/{session}/apply', [EstimateGenerationController::class, 'apply'])->middleware('authorize:estimate_generation.apply,project,project')->name('apply');
        Route::get('/{session}/normative-candidates/search', [EstimateGenerationController::class, 'searchNormativeCandidates'])->middleware('authorize:estimate_generation.select_normative,project,project')->name('normative-candidates.search');
        Route::post('/{session}/normative-candidate', [EstimateGenerationController::class, 'selectNormativeCandidate'])->middleware('authorize:estimate_generation.select_normative,project,project')->name('normative-candidate.select');
        Route::post('/{session}/rebuild-section', [EstimateGenerationController::class, 'rebuildSection'])->middleware('authorize:estimate_generation.generate,project,project')->name('rebuild-section');
        Route::post('/{session}/feedback', [EstimateGenerationController::class, 'feedback'])->middleware('authorize:estimate_generation.review,project,project')->name('feedback');
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
