<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Middleware\SubstituteBindings;
use App\Support\Routing\AdminRouteStack;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantRagController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\ProjectPulseController;

// ==========================================
// Роуты для Личного кабинета (ЛК)
// ==========================================
Route::middleware(['auth:api', 'organization.context', SubstituteBindings::class])
    ->prefix('api/v1/ai-assistant')
    ->name('lk.ai-assistant.')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat'])->name('chat');
        Route::post('/actions/preview', [AIAssistantController::class, 'previewAction'])->name('actions.preview');
        Route::post('/actions/execute', [AIAssistantController::class, 'executeAction'])->name('actions.execute');
        Route::get('/conversations', [AIAssistantController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->name('deleteConversation');
        Route::get('/usage', [AIAssistantController::class, 'usage'])->name('usage');
    });

// ==========================================
// Роуты для Админ-панели
// ==========================================
Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-assistant')
    ->name('admin.ai-assistant.')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat'])->name('chat');
        Route::post('/actions/preview', [AIAssistantController::class, 'previewAction'])->name('actions.preview');
        Route::post('/actions/execute', [AIAssistantController::class, 'executeAction'])->name('actions.execute');
        Route::get('/conversations', [AIAssistantController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->name('deleteConversation');
        Route::get('/usage', [AIAssistantController::class, 'usage'])->name('usage');
        Route::get('/rag/status', [AIAssistantRagController::class, 'status'])
            ->middleware('authorize:admin.ai_assistant.rag.view')
            ->name('rag.status');
        Route::post('/rag/reindex', [AIAssistantRagController::class, 'reindex'])
            ->middleware('authorize:admin.ai_assistant.rag.manage')
            ->name('rag.reindex');
    });

// ==========================================
// Роуты для мобильного приложения
// ==========================================
Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', SubstituteBindings::class])
    ->prefix('api/v1/mobile/ai-assistant')
    ->name('mobile.ai-assistant.')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat'])->name('chat');
        Route::post('/actions/preview', [AIAssistantController::class, 'previewAction'])->name('actions.preview');
        Route::post('/actions/execute', [AIAssistantController::class, 'executeAction'])->name('actions.execute');
        Route::get('/conversations', [AIAssistantController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->name('deleteConversation');
        Route::get('/usage', [AIAssistantController::class, 'usage'])->name('usage');
    });

// ==========================================
// Роуты для скачивания AI отчетов
// ==========================================
Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-reports')
    ->name('admin.ai-reports.')
    ->group(function () {
        Route::get('/download/{token}', [AiReportsDownloadController::class, 'download'])->name('download');
    });

// ==========================================
// Роуты для пульса проектов
// ==========================================
Route::middleware(AdminRouteStack::middleware([SubstituteBindings::class]))
    ->prefix('api/v1/admin/ai-assistant/project-pulse')
    ->name('admin.project-pulse.')
    ->group(function () {
        Route::get('current', [ProjectPulseController::class, 'current'])
            ->middleware('authorize:admin.ai_assistant.project_pulse.view')
            ->name('current');
        Route::post('generate', [ProjectPulseController::class, 'generate'])
            ->middleware('authorize:admin.ai_assistant.project_pulse.generate')
            ->name('generate');
        Route::get('reports', [ProjectPulseController::class, 'reports'])
            ->middleware('authorize:admin.ai_assistant.project_pulse.view')
            ->name('reports.index');
        Route::get('reports/{report}', [ProjectPulseController::class, 'show'])
            ->middleware('authorize:admin.ai_assistant.project_pulse.view')
            ->name('reports.show');
        Route::delete('reports/{report}', [ProjectPulseController::class, 'destroy'])
            ->middleware('authorize:admin.ai_assistant.project_pulse.delete')
            ->name('reports.destroy');
    });
