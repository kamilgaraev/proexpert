<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestDashboardController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestCalendarController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestTemplateController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestPaymentController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile\SiteRequestController as MobileSiteRequestController;

/*
|--------------------------------------------------------------------------
| Site Requests Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля "Заявки с объекта"
| Все маршруты защищены middleware: auth:api_admin, organization.context, site_requests.active
|
*/

// ============================================
// ADMIN API
// ============================================
Route::prefix('api/v1/admin/site-requests')
    ->name('admin.site_requests.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'site_requests.active'])
    ->group(function () {

        // ============================================
        // Дашборд и статистика (ПЕРЕД CRUD)
        // ============================================
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/statistics', [SiteRequestDashboardController::class, 'statistics'])->name('statistics');
            Route::get('/overdue', [SiteRequestDashboardController::class, 'overdue'])->name('overdue');
        });

        // ============================================
        // Календарь (ПЕРЕД CRUD)
        // ============================================
        Route::prefix('calendar')->name('calendar.')->group(function () {
            Route::get('/', [SiteRequestCalendarController::class, 'index'])->name('index');
            Route::get('/by-date', [SiteRequestCalendarController::class, 'byDate'])->name('by_date');
            Route::get('/export', [SiteRequestCalendarController::class, 'export'])->name('export');
        });

        // ============================================
        // Шаблоны (ПЕРЕД CRUD)
        // ============================================
        Route::prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [SiteRequestTemplateController::class, 'index'])->name('index');
            Route::get('/popular', [SiteRequestTemplateController::class, 'popular'])->name('popular');
            Route::post('/', [SiteRequestTemplateController::class, 'store'])->name('store');
            Route::get('/{id}', [SiteRequestTemplateController::class, 'show'])->name('show');
            Route::put('/{id}', [SiteRequestTemplateController::class, 'update'])->name('update');
            Route::delete('/{id}', [SiteRequestTemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{templateId}/create', [SiteRequestTemplateController::class, 'createFromTemplate'])->name('create_from');
        });

        // ============================================
        // Платежи из заявок (ПЕРЕД CRUD)
        // ============================================
        Route::prefix('payment')->name('payment.')->group(function () {
            Route::get('/available', [SiteRequestPaymentController::class, 'getAvailableForPayment'])->name('available');
            Route::post('/create', [SiteRequestPaymentController::class, 'createPayment'])->name('create');
        });

        // ============================================
        // Основной CRUD заявок (ПОСЛЕ специфичных маршрутов)
        // ============================================
        Route::get('/', [SiteRequestController::class, 'index'])->name('index');
        Route::post('/', [SiteRequestController::class, 'store'])->name('store');
        Route::get('/{id}', [SiteRequestController::class, 'show'])->name('show');
        Route::put('/{id}', [SiteRequestController::class, 'update'])->name('update');
        Route::delete('/{id}', [SiteRequestController::class, 'destroy'])->name('destroy');

        // ============================================
        // Действия с заявками
        // ============================================
        Route::post('/{id}/status', [SiteRequestController::class, 'changeStatus'])->name('change_status');
        Route::post('/{id}/assign', [SiteRequestController::class, 'assign'])->name('assign');
        Route::post('/{id}/submit', [SiteRequestController::class, 'submit'])->name('submit');
    });

// ============================================
// MOBILE API
// ============================================
Route::prefix('api/v1/mobile/site-requests')
    ->name('mobile.site_requests.')
    ->middleware(['auth:api', 'organization.context', 'site_requests.active'])
    ->group(function () {

        // ============================================
        // Шаблоны (ПЕРЕД CRUD)
        // ============================================
        Route::get('/templates', [MobileSiteRequestController::class, 'templates'])->name('templates');
        Route::post('/from-template/{templateId}', [MobileSiteRequestController::class, 'createFromTemplate'])->name('from_template');

        // ============================================
        // Календарь (ПЕРЕД CRUD)
        // ============================================
        Route::get('/calendar', [MobileSiteRequestController::class, 'calendar'])->name('calendar');

        // ============================================
        // CRUD заявок для прорабов (ПОСЛЕ специфичных маршрутов)
        // ============================================
        Route::get('/', [MobileSiteRequestController::class, 'index'])->name('index');
        Route::post('/', [MobileSiteRequestController::class, 'store'])->name('store');
        Route::get('/{id}', [MobileSiteRequestController::class, 'show'])->name('show');
        Route::put('/{id}', [MobileSiteRequestController::class, 'update'])->name('update');

        // ============================================
        // Действия с заявками
        // ============================================
        Route::post('/{id}/submit', [MobileSiteRequestController::class, 'submit'])->name('submit');
        Route::post('/{id}/cancel', [MobileSiteRequestController::class, 'cancel'])->name('cancel');
        Route::post('/{id}/complete', [MobileSiteRequestController::class, 'complete'])->name('complete');
    });

