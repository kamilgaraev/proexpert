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
    'authorize:admin.access',
    'interface:admin',
    'project.context',
])
    ->prefix('api/v1/admin/projects/{project}/estimate-generation/sessions')
    ->name('api.v1.admin.projects.estimate-generation.sessions.')
    ->group(function (): void {
        Route::get('/', [EstimateGenerationController::class, 'index'])->name('index');
        Route::post('/', [EstimateGenerationController::class, 'store'])->name('store');
        Route::get('/{session}/documents', [EstimateGenerationDocumentController::class, 'index'])->name('documents.index');
        Route::post('/{session}/documents', [EstimateGenerationController::class, 'uploadDocuments'])->name('documents.store');
        Route::get('/{session}/documents/{document}', [EstimateGenerationDocumentController::class, 'show'])->name('documents.show');
        Route::post('/{session}/documents/{document}/retry', [EstimateGenerationDocumentController::class, 'retry'])->name('documents.retry');
        Route::post('/{session}/documents/{document}/ignore', [EstimateGenerationDocumentController::class, 'ignore'])->name('documents.ignore');
        Route::post('/{session}/analyze', [EstimateGenerationController::class, 'analyze'])->name('analyze');
        Route::post('/{session}/generate', [EstimateGenerationController::class, 'generate'])->name('generate');
        Route::get('/{session}/status', [EstimateGenerationController::class, 'status'])->name('status');
        Route::get('/{session}/packages', [EstimateGenerationController::class, 'packages'])->name('packages.index');
        Route::get('/{session}/packages/{package}', [EstimateGenerationController::class, 'package'])->name('packages.show');
        Route::get('/{session}', [EstimateGenerationController::class, 'show'])->name('show');
        Route::get('/{session}/draft', [EstimateGenerationController::class, 'draft'])->name('draft');
        Route::get('/{session}/export', [EstimateGenerationController::class, 'export'])->name('export');
        Route::post('/{session}/apply', [EstimateGenerationController::class, 'apply'])->name('apply');
        Route::get('/{session}/normative-candidates/search', [EstimateGenerationController::class, 'searchNormativeCandidates'])->name('normative-candidates.search');
        Route::post('/{session}/normative-candidate', [EstimateGenerationController::class, 'selectNormativeCandidate'])->name('normative-candidate.select');
        Route::post('/{session}/rebuild-section', [EstimateGenerationController::class, 'rebuildSection'])->name('rebuild-section');
        Route::post('/{session}/feedback', [EstimateGenerationController::class, 'feedback'])->name('feedback');
    });

Route::middleware([
    'api',
    'auth:api_admin',
    'auth.jwt:api_admin',
    'organization.context',
    'authorize:admin.access',
    'interface:admin',
])
    ->prefix('api/v1/admin/estimate-generation/normatives')
    ->name('api.v1.admin.estimate-generation.normatives.')
    ->group(function (): void {
        Route::get('/statuses', [EstimateNormativeStatusController::class, 'index'])->name('statuses.index');
    });
