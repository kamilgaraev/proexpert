<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\HoldingReportsController;

Route::prefix('holdings/{holdingId}/reports')
    ->middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi-organization'])
    ->name('holdings.reports.')
    ->group(function () {
        
        // Основной дашборд холдинга - ГЛАВНАЯ ФУНКЦИЯ
        Route::get('/dashboard', [HoldingReportsController::class, 'getDashboard'])
            ->name('dashboard');
        
        // Сравнение организаций внутри холдинга
        Route::get('/organizations-comparison', [HoldingReportsController::class, 'getOrganizationsComparison'])
            ->name('organizationsComparison');
        
        // Финансовый отчет за период
        Route::get('/financial', [HoldingReportsController::class, 'getFinancialReport'])
            ->name('financial');
        
        // KPI метрики холдинга
        Route::get('/kpi', [HoldingReportsController::class, 'getKPIMetrics'])
            ->name('kpi');
        
        // Быстрые метрики для виджетов
        Route::get('/quick-metrics', [HoldingReportsController::class, 'getQuickMetrics'])
            ->name('quickMetrics');
        
        // Очистка кэша (только для владельцев)
        Route::delete('/cache', [HoldingReportsController::class, 'clearCache'])
            ->middleware(['authorize:multi-organization.manage'])
            ->name('clearCache');
    });