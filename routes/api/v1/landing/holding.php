<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\HoldingLandingController;
use App\Http\Controllers\Api\V1\Landing\SiteBlocksController;
use App\Http\Controllers\Api\V1\Landing\SiteAssetsController;
use App\Http\Controllers\Api\V1\Landing\HoldingReportsController;

// ============================================================================
// API УПРАВЛЕНИЯ ХОЛДИНГОМ В ЛК
// Требуют авторизации и активный модуль multi-organization
// Холдинг определяется автоматически из контекста текущей организации (is_holding=true)
// ============================================================================

Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi-organization'])
    ->prefix('holding')
    ->name('holding.')
    ->group(function () {
        
        // ========================================================================
        // УПРАВЛЕНИЕ ЛЕНДИНГОМ (визуальный редактор как в Тильде)
        // ========================================================================
        Route::prefix('site')->name('site.')->group(function () {
            
            // Получить настройки лендинга (создать если нет)
            Route::get('/', [HoldingLandingController::class, 'show'])
                ->middleware(['authorize:multi-organization.website.view'])
                ->name('show');
            
            // Обновить настройки лендинга (SEO, тема, домен)
            Route::put('/', [HoldingLandingController::class, 'update'])
                ->middleware(['authorize:multi-organization.website.edit'])
                ->name('update');
            
            // Опубликовать лендинг (применить изменения)
            Route::post('/publish', [HoldingLandingController::class, 'publish'])
                ->middleware(['authorize:multi-organization.website.publish'])
                ->name('publish');
            
            // === БЛОКИ КОНТЕНТА ===
            
            Route::prefix('blocks')->name('blocks.')->group(function () {
                // Список блоков
                Route::get('/', [SiteBlocksController::class, 'indexForHolding'])
                    ->middleware(['authorize:multi-organization.website.view'])
                    ->name('index');
                
                // Создать блок
                Route::post('/', [SiteBlocksController::class, 'storeForHolding'])
                    ->middleware(['authorize:multi-organization.website.edit'])
                    ->name('store');
                
                // Обновить блок
                Route::put('/{blockId}', [SiteBlocksController::class, 'updateForHolding'])
                    ->middleware(['authorize:multi-organization.website.edit'])
                    ->name('update');
                
                // Опубликовать блок
                Route::post('/{blockId}/publish', [SiteBlocksController::class, 'publishForHolding'])
                    ->middleware(['authorize:multi-organization.website.publish'])
                    ->name('publish');
                
                // Дублировать блок
                Route::post('/{blockId}/duplicate', [SiteBlocksController::class, 'duplicateForHolding'])
                    ->middleware(['authorize:multi-organization.website.edit'])
                    ->name('duplicate');
                
                // Удалить блок
                Route::delete('/{blockId}', [SiteBlocksController::class, 'destroyForHolding'])
                    ->middleware(['authorize:multi-organization.website.edit'])
                    ->name('destroy');
                
                // Изменить порядок блоков (drag & drop)
                Route::put('/reorder', [SiteBlocksController::class, 'reorderForHolding'])
                    ->middleware(['authorize:multi-organization.website.edit'])
                    ->name('reorder');
            });
            
            // === МЕДИАФАЙЛЫ ===
            
            Route::prefix('assets')->name('assets.')->group(function () {
                // Список файлов
                Route::get('/', [SiteAssetsController::class, 'indexForHolding'])
                    ->middleware(['authorize:multi-organization.website.view'])
                    ->name('index');
                
                // Загрузить файл
                Route::post('/', [SiteAssetsController::class, 'storeForHolding'])
                    ->middleware(['authorize:multi-organization.website.assets.upload'])
                    ->name('store');
                
                // Обновить метаданные
                Route::put('/{assetId}', [SiteAssetsController::class, 'updateForHolding'])
                    ->middleware(['authorize:multi-organization.website.assets.manage'])
                    ->name('update');
                
                // Удалить файл
                Route::delete('/{assetId}', [SiteAssetsController::class, 'destroyForHolding'])
                    ->middleware(['authorize:multi-organization.website.assets.manage'])
                    ->name('destroy');
            });
        });
        
        // ========================================================================
        // АНАЛИТИКА И ОТЧЕТЫ ХОЛДИНГА
        // ========================================================================
        Route::prefix('analytics')->name('analytics.')->group(function () {
            
            // Главный дашборд холдинга (метрики всех дочерних организаций)
            Route::get('/dashboard', [HoldingReportsController::class, 'getDashboard'])
                ->middleware(['authorize:multi-organization.reports.dashboard'])
                ->name('dashboard');
            
            // Сравнение организаций
            Route::get('/comparison', [HoldingReportsController::class, 'getOrganizationsComparison'])
                ->middleware(['authorize:multi-organization.reports.comparison'])
                ->name('comparison');
            
            // Финансовый отчет
            Route::get('/financial', [HoldingReportsController::class, 'getFinancialReport'])
                ->middleware(['authorize:multi-organization.reports.financial'])
                ->name('financial');
            
            // KPI метрики
            Route::get('/kpi', [HoldingReportsController::class, 'getKPIMetrics'])
                ->middleware(['authorize:multi-organization.reports.kpi'])
                ->name('kpi');
            
            // Быстрые метрики (для виджетов)
            Route::get('/quick-metrics', [HoldingReportsController::class, 'getQuickMetrics'])
                ->middleware(['authorize:multi-organization.reports.view'])
                ->name('quickMetrics');
            
            // Очистка кэша отчетов
            Route::delete('/cache', [HoldingReportsController::class, 'clearCache'])
                ->middleware(['authorize:multi-organization.cache.clear'])
                ->name('clearCache');
        });
    });

