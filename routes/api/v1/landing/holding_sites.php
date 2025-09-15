<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\HoldingLandingController;
use App\Http\Controllers\Api\V1\Landing\SiteBlocksController;
use App\Http\Controllers\Api\V1\Landing\SiteAssetsController;

// Управление лендингом холдинга (упрощенная Тильда - один лендинг на холдинг)
Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context', 'module.access:multi-organization'])
    ->prefix('holdings/{holdingId}/landing')
    ->name('holdingLanding.')
    ->group(function () {
        
        // Получить лендинг холдинга (создать если нет)
        Route::get('/', [HoldingLandingController::class, 'show'])
            ->middleware(['authorize:multi-organization.website.view'])
            ->name('show');
        
        // Обновить настройки лендинга
        Route::put('/', [HoldingLandingController::class, 'update'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('update');
        
        // Опубликовать лендинг
        Route::post('/publish', [HoldingLandingController::class, 'publish'])
            ->middleware(['authorize:multi-organization.website.publish'])
            ->name('publish');
        
        // === УПРАВЛЕНИЕ БЛОКАМИ КОНТЕНТА (как в Тильде) ===
        
        // Получить все блоки лендинга
        Route::get('/blocks', [SiteBlocksController::class, 'indexForHolding'])
            ->middleware(['authorize:multi-organization.website.view'])
            ->name('blocks.index');
        
        // Создать новый блок
        Route::post('/blocks', [SiteBlocksController::class, 'storeForHolding'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('blocks.store');
        
        // Обновить блок
        Route::put('/blocks/{blockId}', [SiteBlocksController::class, 'updateForHolding'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('blocks.update');
        
        // Опубликовать блок
        Route::post('/blocks/{blockId}/publish', [SiteBlocksController::class, 'publishForHolding'])
            ->middleware(['authorize:multi-organization.website.publish'])
            ->name('blocks.publish');
        
        // Дублировать блок
        Route::post('/blocks/{blockId}/duplicate', [SiteBlocksController::class, 'duplicateForHolding'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('blocks.duplicate');
        
        // Удалить блок
        Route::delete('/blocks/{blockId}', [SiteBlocksController::class, 'destroyForHolding'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('blocks.destroy');
        
        // Изменить порядок блоков (drag & drop как в Тильде)
        Route::put('/blocks/reorder', [SiteBlocksController::class, 'reorderForHolding'])
            ->middleware(['authorize:multi-organization.website.edit'])
            ->name('blocks.reorder');
        
        // === УПРАВЛЕНИЕ МЕДИАФАЙЛАМИ ===
        
        // Получить все файлы лендинга
        Route::get('/assets', [SiteAssetsController::class, 'indexForHolding'])
            ->middleware(['authorize:multi-organization.website.view'])
            ->name('assets.index');
        
        // Загрузить файл
        Route::post('/assets', [SiteAssetsController::class, 'storeForHolding'])
            ->middleware(['authorize:multi-organization.website.assets.upload'])
            ->name('assets.store');
        
        // Обновить метаданные файла
        Route::put('/assets/{assetId}', [SiteAssetsController::class, 'updateForHolding'])
            ->middleware(['authorize:multi-organization.website.assets.manage'])
            ->name('assets.update');
        
        // Удалить файл
        Route::delete('/assets/{assetId}', [SiteAssetsController::class, 'destroyForHolding'])
            ->middleware(['authorize:multi-organization.website.assets.manage'])
            ->name('assets.destroy');
    });
