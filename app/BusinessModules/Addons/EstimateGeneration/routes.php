<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationActionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationAnalysisBasisController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDialogueController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationQuestionController;
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
        Route::post('/{session}/documents/reuse', [EstimateGenerationDocumentController::class, 'reuse'])->middleware('authorize:estimate_generation.upload_documents,project,project')->name('documents.reuse');
        Route::get('/{session}/documents/{document}', [EstimateGenerationDocumentController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.show');
        Route::post('/{session}/documents/{document}/retry', [EstimateGenerationDocumentController::class, 'retry'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.retry');
        Route::post('/{session}/documents/{document}/stop', [EstimateGenerationDocumentController::class, 'stop'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.stop');
        Route::post('/{session}/documents/{document}/confirm-cost', [EstimateGenerationDocumentController::class, 'confirmCost'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.confirm-cost');
        Route::post('/{session}/documents/{document}/ignore', [EstimateGenerationDocumentController::class, 'ignore'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.ignore');
        Route::post('/{session}/documents/{document}/pages/retry', [EstimateGenerationDocumentController::class, 'retryPages'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.pages.retry');
        Route::post('/{session}/documents/{document}/pages/exclude', [EstimateGenerationDocumentController::class, 'excludePages'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.pages.exclude');
        Route::post('/{session}/documents/{document}/pages/restore', [EstimateGenerationDocumentController::class, 'restorePages'])->middleware('authorize:estimate_generation.review,project,project')->name('documents.pages.restore');
        Route::post('/{session}/analyze', [EstimateGenerationActionController::class, 'analyze'])->middleware('authorize:estimate_generation.generate,project,project')->name('analyze');
        Route::post('/{session}/generate', [EstimateGenerationActionController::class, 'generate'])->middleware('authorize:estimate_generation.generate,project,project')->name('generate');
        Route::post('/{session}/confirm-input', [EstimateGenerationActionController::class, 'confirmInput'])->middleware('authorize:estimate_generation.review,project,project')->name('confirm-input');
        Route::get('/{session}/analysis-basis', [EstimateGenerationAnalysisBasisController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('analysis-basis.show');
        Route::post('/{session}/retry', [EstimateGenerationActionController::class, 'retry'])->middleware('authorize:estimate_generation.generate,project,project')->name('retry');
        Route::post('/{session}/cancel', [EstimateGenerationActionController::class, 'cancel'])->middleware('authorize:estimate_generation.generate,project,project')->name('cancel');
        Route::post('/{session}/archive', [EstimateGenerationActionController::class, 'archive'])->middleware('authorize:estimate_generation.generate,project,project')->name('archive');
        Route::get('/{session}/status', [EstimateGenerationSessionController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('status');
        Route::get('/{session}/snapshot', [EstimateGenerationSessionController::class, 'snapshot'])->middleware('authorize:estimate_generation.view,project,project')->name('snapshot');
        Route::get('/{session}/packages', [EstimateGenerationPackageController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.index');
        Route::get('/{session}/packages/{package}', [EstimateGenerationPackageController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('packages.show');
        Route::get('/{session}/draft', [EstimateGenerationPackageController::class, 'draft'])->middleware('authorize:estimate_generation.view,project,project')->name('draft');
        Route::get('/{session}/review-items', [EstimateGenerationReviewController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('review-items');
        Route::get('/{session}/review-exceptions', [EstimateGenerationReviewController::class, 'exceptions'])->middleware('authorize:estimate_generation.view,project,project')->name('review-exceptions');
        Route::get('/{session}/questions', [EstimateGenerationQuestionController::class, 'index'])->middleware('authorize:estimate_generation.review,project,project')->name('questions.index');
        Route::post('/{session}/questions/{question}/answer', [EstimateGenerationQuestionController::class, 'answer'])->where('question', '[a-z][a-z0-9_]{1,79}')->middleware('authorize:estimate_generation.review,project,project')->name('questions.answer');
        Route::post('/{session}/assistant/interpret', [EstimateGenerationDialogueController::class, 'interpret'])->middleware('authorize:estimate_generation.review,project,project')->name('assistant.interpret');
        Route::get('/{session}/assistant/proposals', [EstimateGenerationDialogueController::class, 'history'])->middleware('authorize:estimate_generation.view,project,project')->name('assistant.proposals.index');
        Route::get('/{session}/assistant/proposals/{proposal}', [EstimateGenerationDialogueController::class, 'show'])->whereUuid('proposal')->middleware('authorize:estimate_generation.view,project,project')->name('assistant.proposals.show');
        Route::get('/{session}/assistant/proposals/{proposal}/items', [EstimateGenerationDialogueController::class, 'items'])->whereUuid('proposal')->middleware('authorize:estimate_generation.view,project,project')->name('assistant.proposals.items');
        Route::post('/{session}/assistant/proposals/{proposal}/apply', [EstimateGenerationDialogueController::class, 'apply'])->whereUuid('proposal')->middleware('authorize:estimate_generation.review,project,project')->name('assistant.proposals.apply');
        Route::post('/{session}/assistant/proposals/{proposal}/cancel', [EstimateGenerationDialogueController::class, 'cancel'])->whereUuid('proposal')->middleware('authorize:estimate_generation.review,project,project')->name('assistant.proposals.cancel');
        Route::post('/{session}/assistant/proposals/{proposal}/undo-preview', [EstimateGenerationDialogueController::class, 'undoPreview'])->whereUuid('proposal')->middleware('authorize:estimate_generation.review,project,project')->name('assistant.proposals.undo-preview');
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
