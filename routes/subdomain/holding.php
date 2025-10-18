<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldingController;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;

// ============================================================================
// ПУБЛИЧНЫЕ РОУТЫ НА ПОДДОМЕНЕ ХОЛДИНГА
// Например: stroitelnyj-holding-alfa.prohelper.pro/
// Middleware DetectHoldingSubdomain автоматически определяет холдинг по slug
// ============================================================================

// Главная страница холдинга (публичный лендинг - HTML)
Route::get('/', [HoldingController::class, 'index'])->name('holding.home');

// API для получения данных лендинга (для SPA фронтенда)
Route::get('/api/site-data', [HoldingController::class, 'getSiteData'])->name('holding.api.siteData');

// ============================================================================
// ЗАЩИЩЕННЫЕ РОУТЫ (требуют авторизации)
// ============================================================================
Route::middleware(['auth:api_landing', 'jwt.auth'])->group(function () {
    
    // Дашборд холдинга на поддомене
    Route::get('/dashboard', [HoldingController::class, 'dashboard'])->name('holding.dashboard');
    
    // Список дочерних организаций холдинга
    Route::get('/organizations', [HoldingController::class, 'childOrganizations'])->name('holding.organizations');
    
    // API endpoints для работы с данными холдинга
    Route::prefix('api')->group(function () {
        
        // Иерархия организаций холдинга
        Route::get('/hierarchy', [MultiOrganizationController::class, 'getHierarchy'])->name('holding.api.hierarchy');
        
        // Данные конкретной организации
        Route::get('/organization/{organizationId}', [MultiOrganizationController::class, 'getOrganizationData'])->name('holding.api.organization');
        
        // Добавление дочерней организации (только владельцы)
        Route::middleware(['role:organization_owner'])->group(function () {
            Route::post('/add-child', [MultiOrganizationController::class, 'addChildOrganization'])->name('holding.api.addChild');
        });
    });
});

