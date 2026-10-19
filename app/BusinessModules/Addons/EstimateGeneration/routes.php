<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationActionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationAnalysisBasisController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationAnswerReceiptController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDialogueController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationEvaluationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationFactoryController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationManualFactsController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPrerequisiteController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationQuestionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationReviewController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationRoomIdentityController;
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
        Route::get('/capabilities', [EstimateGenerationSessionController::class, 'capabilities'])->middleware('authorize:estimate_generation.create,project,project')->name('capabilities');
        Route::post('/', [EstimateGenerationSessionController::class, 'store'])->middleware('authorize:estimate_generation.create,project,project')->name('store');
        Route::get('/creation-requests/{requestId}', [EstimateGenerationSessionController::class, 'creationRequest'])->whereUuid('requestId')->middleware('authorize:estimate_generation.create,project,project')->name('creation-request');
        Route::get('/{session}/documents', [EstimateGenerationDocumentController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('documents.index');
        Route::post('/{session}/documents', [EstimateGenerationDocumentController::class, 'upload'])->middleware('authorize:estimate_generation.upload_documents,project,project')->name('documents.store');
        Route::post('/{session}/documents/reuse', [EstimateGenerationDocumentController::class, 'reuse'])->middleware('authorize:estimate_generation.upload_documents,project,project')->name('documents.reuse');
        Route::get('/{session}/document-admissions/{requestId}', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentAdmissionController::class, 'show'])->whereUuid('requestId')->middleware('authorize:estimate_generation.view,project,project')->name('documents.admission');
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
        Route::post('/{session}/operations/quote', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationCreditController::class, 'quote'])->name('operations.quote');
        Route::post('/{session}/operations/documents', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationCreditController::class, 'startDocuments'])->middleware('authorize:estimate_generation.generate,project,project')->name('operations.documents');
        Route::get('/{session}/operations/{operation}', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationCreditController::class, 'show'])->whereUuid('operation')->middleware('authorize:estimate_generation.view,project,project')->name('operations.show');
        Route::get('/{session}/qa-expense', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationExpenseDiagnosticsController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('qa-expense');
        Route::get('/{session}/factory', [EstimateGenerationFactoryController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('factory.show');
        Route::post('/{session}/factory/inspections', [EstimateGenerationFactoryController::class, 'inspect'])->middleware('authorize:estimate_generation.review,project,project')->name('factory.inspect');
        Route::post('/{session}/factory/planning', [EstimateGenerationFactoryController::class, 'planning'])->middleware('authorize:estimate_generation.review,project,project')->name('factory.planning');
        Route::post('/{session}/confirm-input', [EstimateGenerationActionController::class, 'confirmInput'])->middleware('authorize:estimate_generation.review,project,project')->name('confirm-input');
        Route::get('/{session}/geometry-inventory', [EstimateGenerationManualFactsController::class, 'inventory'])->middleware('authorize:estimate_generation.view,project,project')->name('geometry-inventory.show');
        Route::get('/{session}/description-proposals', [EstimateGenerationManualFactsController::class, 'descriptionProposals'])->middleware('authorize:estimate_generation.view,project,project')->name('description-proposals.show');
        Route::post('/{session}/initial-facts', [EstimateGenerationManualFactsController::class, 'store'])->middleware('authorize:estimate_generation.review,project,project')->name('initial-facts');
        Route::get('/{session}/evaluation', [EstimateGenerationEvaluationController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation');
        Route::get('/{session}/evaluation/requirement-sources', [EstimateGenerationEvaluationController::class, 'requirementSources'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.requirement-sources');
        Route::post('/{session}/evaluation/requirement', [EstimateGenerationEvaluationController::class, 'requirement'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.requirement');
        Route::post('/{session}/evaluation/recalculate', [EstimateGenerationEvaluationController::class, 'recalculate'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.recalculate');
        Route::post('/{session}/evaluation/price', [EstimateGenerationEvaluationController::class, 'price'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.price');
        Route::get('/{session}/evaluation/technologies', [EstimateGenerationEvaluationController::class, 'technologies'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.technologies');
        Route::post('/{session}/evaluation/technology', [EstimateGenerationEvaluationController::class, 'technology'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.technology');
        Route::post('/{session}/evaluation/currency', [EstimateGenerationEvaluationController::class, 'currency'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.currency');
        Route::post('/{session}/evaluation/contingency', [EstimateGenerationEvaluationController::class, 'contingency'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.contingency');
        Route::post('/{session}/evaluation/quantity', [EstimateGenerationEvaluationController::class, 'quantity'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.quantity');
        Route::post('/{session}/evaluation/scope', [EstimateGenerationEvaluationController::class, 'scope'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.scope');
        Route::post('/{session}/evaluation/scenarios', [EstimateGenerationEvaluationController::class, 'scenarios'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.scenarios');
        Route::get('/{session}/evaluation/scenario-parameters', [EstimateGenerationEvaluationController::class, 'scenarioParameters'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.scenario-parameters');
        Route::post('/{session}/evaluation/confirm-scenario', [EstimateGenerationEvaluationController::class, 'confirmScenario'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.confirm-scenario');
        Route::get('/{session}/evaluation/price-sources', [EstimateGenerationEvaluationController::class, 'sources'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.price-sources');
        Route::get('/{session}/evaluation/quantity-sources', [EstimateGenerationEvaluationController::class, 'quantitySources'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.quantity-sources');
        Route::get('/{session}/evaluation/room-identities', [EstimateGenerationRoomIdentityController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('evaluation.room-identities');
        Route::post('/{session}/evaluation/room-identity', [EstimateGenerationRoomIdentityController::class, 'confirm'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.room-identity');
        Route::post('/{session}/evaluation/work-entity', [EstimateGenerationRoomIdentityController::class, 'bindWork'])->middleware('authorize:estimate_generation.review,project,project')->name('evaluation.work-entity');
        Route::post('/{session}/evaluation/pricing-source', [EstimateGenerationEvaluationController::class, 'pricingSource'])->middleware('authorize:estimate_generation.generate,project,project')->name('evaluation.pricing-source');
        Route::get('/{session}/analysis-basis', [EstimateGenerationAnalysisBasisController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('analysis-basis.show');
        Route::get('/{session}/evidence-sources', [\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationEvidenceController::class, 'show'])->middleware('authorize:estimate_generation.view,project,project')->name('evidence-sources.show');
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
        Route::get('/{session}/prerequisites', [EstimateGenerationPrerequisiteController::class, 'index'])->middleware('authorize:estimate_generation.view,project,project')->name('prerequisites.index');
        Route::post('/{session}/prerequisites/refresh', [EstimateGenerationPrerequisiteController::class, 'refresh'])->middleware('authorize:estimate_generation.review,project,project')->name('prerequisites.refresh');
        Route::post('/{session}/prerequisites/answer', [EstimateGenerationPrerequisiteController::class, 'answer'])->middleware('authorize:estimate_generation.review,project,project')->name('prerequisites.answer');
        Route::get('/{session}/questions', [EstimateGenerationQuestionController::class, 'index'])->middleware('authorize:estimate_generation.review,project,project')->name('questions.index');
        Route::get('/{session}/questions/{question}/answer-receipt', [EstimateGenerationAnswerReceiptController::class, 'show'])->where('question', '[a-z][a-z0-9_]{1,79}')->middleware('authorize:estimate_generation.view,project,project')->name('questions.answer-receipt');
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
