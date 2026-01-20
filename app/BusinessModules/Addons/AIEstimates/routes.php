<?php

use App\BusinessModules\Addons\AIEstimates\Http\Controllers\AIEstimateGeneratorController;
use Illuminate\Support\Facades\Route;

// AI Estimates routes - в контексте проекта
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/admin/projects/{project}/ai-estimates')
    ->name('api.v1.admin.projects.ai-estimates.')
    ->group(function () {
        // Генерация сметы
        Route::post('/generate', [AIEstimateGeneratorController::class, 'generate'])
            ->name('generate');

        // История генераций
        Route::get('/history', [AIEstimateGeneratorController::class, 'history'])
            ->name('history');
        
        Route::get('/history/{generation}', [AIEstimateGeneratorController::class, 'show'])
            ->name('history.show');

        // Feedback система
        Route::post('/history/{generation}/feedback', [AIEstimateGeneratorController::class, 'provideFeedback'])
            ->name('history.feedback');

        // Экспорт сгенерированной сметы
        Route::post('/history/{generation}/export', [AIEstimateGeneratorController::class, 'export'])
            ->name('history.export');

        // Лимиты использования
        Route::get('/usage-limits', [AIEstimateGeneratorController::class, 'usageLimits'])
            ->name('usage-limits');

        // Управление кешем
        Route::delete('/cache/clear', [AIEstimateGeneratorController::class, 'clearCache'])
            ->name('cache.clear');
    });
