<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldingController;
use App\Http\Controllers\Api\V1\Landing\SiteLeadsController;

// ============================================================================
// ПУБЛИЧНЫЕ РОУТЫ НА ПОДДОМЕНЕ ХОЛДИНГА
// Например: stroitelnyj-holding-alfa.prohelper.pro/
// Middleware DetectHoldingSubdomain автоматически определяет холдинг по slug
// ============================================================================

// Главная страница холдинга (публичный лендинг - HTML)
Route::get('/', [HoldingController::class, 'index'])->name('holding.home');

// API для получения данных лендинга (для SPA фронтенда)
Route::get('/api/site-data', [HoldingController::class, 'getSiteData'])->name('holding.api.siteData');
Route::post('/api/site-leads', [SiteLeadsController::class, 'storePublic'])->name('holding.api.siteLeads');

// ============================================================================
// ЗАЩИЩЕННЫЕ РОУТЫ (требуют авторизации)
// ============================================================================
Route::middleware(['auth:api_landing', 'jwt.auth'])->group(function () {
    
    // Дашборд холдинга на поддомене
    Route::get('/dashboard', [HoldingController::class, 'dashboard'])->name('holding.dashboard');
    
    // Список дочерних организаций холдинга
    Route::get('/organizations', [HoldingController::class, 'childOrganizations'])->name('holding.organizations');
});
