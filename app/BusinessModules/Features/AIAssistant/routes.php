<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\SystemAnalysisController;

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

// ==========================================
// Роуты для системного анализа проектов
// ==========================================
Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access'])
    ->prefix('api/v1/admin/ai-assistant/system-analysis')
    ->name('admin.system-analysis.')
    ->group(function () {
        Route::post('projects/{project}/analyze', [SystemAnalysisController::class, 'analyzeProject'])->name('analyze-project');
        Route::post('organization/analyze', [SystemAnalysisController::class, 'analyzeOrganization'])->name('analyze-organization');
        Route::get('reports', [SystemAnalysisController::class, 'listReports'])->name('reports.list');
        Route::get('reports/{report}', [SystemAnalysisController::class, 'getReport'])->name('reports.get');
        Route::post('reports/{report}/recalculate', [SystemAnalysisController::class, 'recalculate'])->name('reports.recalculate');
        Route::get('reports/{report}/export/pdf', [SystemAnalysisController::class, 'exportPDF'])->name('reports.export-pdf');
        Route::get('reports/{report}/compare/{previous}', [SystemAnalysisController::class, 'compare'])->name('reports.compare');
        Route::delete('reports/{report}', [SystemAnalysisController::class, 'deleteReport'])->name('reports.delete');
    });

