<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\HoldingReportsController;

Route::prefix('holdings/{holdingId}/reports')
    ->middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi-organization'])
    ->name('holdings.reports.')
    ->group(function () {
        
        // Основной дашборд холдинга - ГЛАВНАЯ ФУНКЦИЯ
        Route::get('/dashboard', [HoldingReportsController::class, 'getDashboard'])
            ->middleware(['authorize:multi-organization.reports.dashboard'])
            ->name('dashboard');
        
        // Сравнение организаций внутри холдинга
        Route::get('/organizations-comparison', [HoldingReportsController::class, 'getOrganizationsComparison'])
            ->middleware(['authorize:multi-organization.reports.comparison'])
            ->name('organizationsComparison');
        
        // Финансовый отчет за период
        Route::get('/financial', [HoldingReportsController::class, 'getFinancialReport'])
            ->middleware(['authorize:multi-organization.reports.financial'])
            ->name('financial');
        
        // KPI метрики холдинга
        Route::get('/kpi', [HoldingReportsController::class, 'getKPIMetrics'])
            ->middleware(['authorize:multi-organization.reports.kpi'])
            ->name('kpi');
        
        // Быстрые метрики для виджетов (базовый доступ к отчетам)
        Route::get('/quick-metrics', [HoldingReportsController::class, 'getQuickMetrics'])
            ->middleware(['authorize:multi-organization.reports.view'])
            ->name('quickMetrics');
        
        // Очистка кэша (только для владельцев)
        Route::delete('/cache', [HoldingReportsController::class, 'clearCache'])
            ->middleware(['authorize:multi-organization.cache.clear'])
            ->name('clearCache');
    });