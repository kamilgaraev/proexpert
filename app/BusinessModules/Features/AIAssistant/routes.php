<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController;

// ==========================================
// Роуты для Личного кабинета (ЛК)
// ==========================================
Route::middleware(['auth:api', 'organization.context'])
    ->prefix('api/v1/ai-assistant')
    ->name('lk.ai-assistant.')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat'])->name('chat');
        Route::get('/conversations', [AIAssistantController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->name('deleteConversation');
        Route::get('/usage', [AIAssistantController::class, 'usage'])->name('usage');
    });

// ==========================================
// Роуты для Админ-панели
// ==========================================
Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('api/v1/admin/ai-assistant')
    ->name('admin.ai-assistant.')
    ->group(function () {
        Route::post('/chat', [AIAssistantController::class, 'chat'])->name('chat');
        Route::get('/conversations', [AIAssistantController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{conversation}', [AIAssistantController::class, 'conversation'])->name('conversation');
        Route::delete('/conversations/{conversation}', [AIAssistantController::class, 'deleteConversation'])->name('deleteConversation');
        Route::get('/usage', [AIAssistantController::class, 'usage'])->name('usage');
    });

// ==========================================
// Роуты для скачивания AI отчетов
// ==========================================
Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access'])
    ->prefix('api/v1/admin/ai-reports')
    ->name('admin.ai-reports.')
    ->group(function () {
        Route::get('/download/{token}', [AiReportsDownloadController::class, 'download'])->name('download');
    });

