<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HoldingApiController;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;

// ============================================================================
// API ХОЛДИНГОВ - ПУБЛИЧНЫЕ И ЗАЩИЩЕННЫЕ ЭНДПОИНТЫ
// Base URL: /api/v1/holding-api
// ============================================================================

// Публичные данные холдинга по slug (без авторизации)
Route::get('{slug}', [HoldingApiController::class, 'getPublicData'])
    ->name('publicData');

// Защищенные эндпоинты (требуют авторизации)
Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->group(function () {
        
        // Дашборд холдинга
        Route::get('{slug}/dashboard', [HoldingApiController::class, 'getDashboardData'])
            ->name('dashboard');
        
        // Список дочерних организаций
        Route::get('{slug}/organizations', [HoldingApiController::class, 'getOrganizations'])
            ->name('organizations');
        
        // Данные конкретной организации
        Route::get('{slug}/organization/{organizationId}', [HoldingApiController::class, 'getOrganizationData'])
            ->name('organizationData');
        
        // Добавление дочерней организации (только владельцы)
        Route::middleware(['authorize:multi-organization.manage'])
            ->post('{slug}/add-child', [MultiOrganizationController::class, 'addChildOrganization'])
            ->name('addChild');
    });

