<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantAttachmentController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantRagController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantCreditsController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantDocumentController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantMemoryController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AssistantSharingController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\ProjectPulseController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

$assistantRoutes = static function (): void {
    Route::post('attachments', [AssistantAttachmentController::class, 'upload'])->name('attachments.upload');
    Route::get('attachments/{attachment}/content', [AssistantAttachmentController::class, 'content'])->whereUuid('attachment')->name('attachments.content');
    Route::get('rag/status', [AIAssistantRagController::class, 'status'])->name('rag.status');
    Route::post('chat', [AIAssistantController::class, 'chat'])->name('chat');
    Route::post('actions/preview', [AIAssistantController::class, 'previewAction'])->name('actions.preview');
    Route::post('actions/execute', [AIAssistantController::class, 'executeAction'])->name('actions.execute');
    Route::get('requests/{requestId}', [AIAssistantController::class, 'requestStatus'])->whereUuid('requestId')->name('requests.status');
    Route::post('requests/{requestId}/cancel', [AIAssistantController::class, 'cancelRequest'])->whereUuid('requestId')->name('requests.cancel');
    Route::get('conversations', [AIAssistantController::class, 'conversations'])->name('conversations.index');
    Route::post('conversations', [AIAssistantController::class, 'createConversation'])->name('conversations.store');
    Route::get('conversations/{conversation}/history', [AIAssistantController::class, 'history'])->whereNumber('conversation')->name('conversations.history');
    Route::get('conversations/{conversation}/participants', [AssistantSharingController::class, 'index'])->whereNumber('conversation')->name('conversations.participants');
    Route::put('conversations/{conversation}/participants', [AssistantSharingController::class, 'update'])->whereNumber('conversation')->name('conversations.share');
    Route::get('conversations/{conversation}', [AIAssistantController::class, 'conversation'])->whereNumber('conversation')->name('conversations.show');
    Route::delete('conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->whereNumber('conversation')->name('conversations.destroy');
    Route::get('memory', [AssistantMemoryController::class, 'index'])->name('memory.index');
    Route::post('memory', [AssistantMemoryController::class, 'store'])->name('memory.store');
    Route::patch('memory/{memory}', [AssistantMemoryController::class, 'update'])->whereUuid('memory')->name('memory.update');
    Route::delete('memory/{memory}', [AssistantMemoryController::class, 'destroy'])->whereUuid('memory')->name('memory.destroy');
    Route::get('credits/balance', [AssistantCreditsController::class, 'balance'])->name('credits.balance');
    Route::get('credits/history', [AssistantCreditsController::class, 'history'])->name('credits.history');
    Route::post('credits/quote', [AssistantCreditsController::class, 'quote'])->name('credits.quote');
    Route::post('credits/purchase', [AssistantCreditsController::class, 'purchase'])->name('credits.purchase');
    Route::get('documents/settings', [AssistantDocumentController::class, 'settings'])->name('documents.settings');
    Route::put('documents/settings', [AssistantDocumentController::class, 'approveBudget'])->name('documents.budget');
    Route::post('documents', [AssistantDocumentController::class, 'register'])->name('documents.register');
    Route::get('documents/{document}', [AssistantDocumentController::class, 'status'])->whereNumber('document')->name('documents.show');
    Route::post('documents/{document}/ocr/quote', [AssistantDocumentController::class, 'quoteOcr'])->whereNumber('document')->name('documents.ocr.quote');
    Route::post('documents/{document}/ocr/confirm', [AssistantDocumentController::class, 'confirmOcr'])->whereNumber('document')->name('documents.ocr.confirm');
    Route::get('reports/{token}/download', [AiReportsDownloadController::class, 'download'])->name('reports.download');
    Route::get('usage', [AIAssistantController::class, 'usage'])->name('usage');
};

Route::middleware(['auth.web:lk', 'auth:api_landing', 'auth.jwt:api_landing', 'organization.context', SubstituteBindings::class])
    ->prefix('api/v1/ai-assistant')->name('lk.ai-assistant.')->group($assistantRoutes);

Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-assistant')->name('admin.ai-assistant.')->group($assistantRoutes);

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', SubstituteBindings::class])
    ->prefix('api/v1/mobile/ai-assistant')->name('mobile.ai-assistant.')->group($assistantRoutes);

Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-assistant')->name('admin.ai-assistant.')->group(static function (): void {
        Route::post('rag/reindex', [AIAssistantRagController::class, 'reindex'])->middleware('authorize:admin.ai_assistant.rag.manage')->name('rag.reindex');
    });

Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-reports')->name('admin.ai-reports.')->group(static function (): void {
        Route::get('download/{token}', [AiReportsDownloadController::class, 'download'])->name('download');
    });

Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-assistant/project-pulse')->name('admin.project-pulse.')->group(static function (): void {
        Route::get('current', [ProjectPulseController::class, 'current'])->middleware('authorize:admin.ai_assistant.project_pulse.view')->name('current');
        Route::post('generate', [ProjectPulseController::class, 'generate'])->middleware('authorize:admin.ai_assistant.project_pulse.generate')->name('generate');
        Route::get('reports', [ProjectPulseController::class, 'reports'])->middleware('authorize:admin.ai_assistant.project_pulse.view')->name('reports.index');
        Route::get('reports/{report}', [ProjectPulseController::class, 'show'])->middleware('authorize:admin.ai_assistant.project_pulse.view')->name('reports.show');
        Route::delete('reports/{report}', [ProjectPulseController::class, 'destroy'])->middleware('authorize:admin.ai_assistant.project_pulse.delete')->name('reports.destroy');
    });
