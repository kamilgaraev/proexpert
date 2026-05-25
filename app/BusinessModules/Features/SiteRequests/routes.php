<?php

use App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile\SiteRequestController as MobileSiteRequestController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestCalendarController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestDashboardController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestPaymentController;
use App\BusinessModules\Features\SiteRequests\Http\Controllers\SiteRequestTemplateController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/site-requests')
    ->name('admin.site_requests.')
    ->middleware(AdminRouteStack::middleware(['site_requests.active']))
    ->group(function () {
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/statistics', [SiteRequestDashboardController::class, 'statistics'])
                ->middleware('authorize:site_requests.statistics')
                ->name('statistics');
            Route::get('/overdue', [SiteRequestDashboardController::class, 'overdue'])
                ->middleware('authorize:site_requests.statistics')
                ->name('overdue');
        });

        Route::prefix('calendar')->name('calendar.')->group(function () {
            Route::get('/', [SiteRequestCalendarController::class, 'index'])
                ->middleware('authorize:site_requests.calendar.view')
                ->name('index');
            Route::get('/by-date', [SiteRequestCalendarController::class, 'byDate'])
                ->middleware('authorize:site_requests.calendar.view')
                ->name('by_date');
            Route::get('/export', [SiteRequestCalendarController::class, 'export'])
                ->middleware('authorize:site_requests.calendar.export')
                ->name('export');
        });

        Route::prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [SiteRequestTemplateController::class, 'index'])
                ->middleware('authorize:site_requests.templates.view')
                ->name('index');
            Route::get('/popular', [SiteRequestTemplateController::class, 'popular'])
                ->middleware('authorize:site_requests.templates.view')
                ->name('popular');
            Route::post('/', [SiteRequestTemplateController::class, 'store'])
                ->middleware('authorize:site_requests.templates.manage')
                ->name('store');
            Route::get('/{id}', [SiteRequestTemplateController::class, 'show'])
                ->middleware('authorize:site_requests.templates.view')
                ->name('show');
            Route::put('/{id}', [SiteRequestTemplateController::class, 'update'])
                ->middleware('authorize:site_requests.templates.manage')
                ->name('update');
            Route::delete('/{id}', [SiteRequestTemplateController::class, 'destroy'])
                ->middleware('authorize:site_requests.templates.manage')
                ->name('destroy');
            Route::post('/{templateId}/create', [SiteRequestTemplateController::class, 'createFromTemplate'])
                ->middleware('authorize:site_requests.create')
                ->name('create_from');
        });

        Route::prefix('payment')->name('payment.')->group(function () {
            Route::get('/available', [SiteRequestPaymentController::class, 'getAvailableForPayment'])
                ->middleware('authorize:site_requests.view')
                ->name('available');
            Route::post('/create', [SiteRequestPaymentController::class, 'createPayment'])
                ->middleware('authorize:payments.invoice.create')
                ->name('create');
        });

        Route::get('/', [SiteRequestController::class, 'index'])
            ->middleware('authorize:site_requests.view')
            ->name('index');
        Route::post('/', [SiteRequestController::class, 'store'])
            ->middleware('authorize:site_requests.create')
            ->name('store');
        Route::get('/{id}', [SiteRequestController::class, 'show'])
            ->middleware('authorize:site_requests.view')
            ->name('show');
        Route::get('/groups/{id}', [SiteRequestController::class, 'showGroup'])
            ->middleware('authorize:site_requests.view')
            ->name('show_group');
        Route::put('/groups/{id}', [SiteRequestController::class, 'updateGroup'])
            ->middleware('authorize:site_requests.edit')
            ->name('update_group');
        Route::put('/{id}', [SiteRequestController::class, 'update'])
            ->middleware('authorize:site_requests.edit')
            ->name('update');
        Route::delete('/{id}', [SiteRequestController::class, 'destroy'])
            ->middleware('authorize:site_requests.delete')
            ->name('destroy');
        Route::post('/{id}/files', [SiteRequestController::class, 'uploadFile'])
            ->middleware('authorize:site_requests.edit')
            ->name('upload_file');
        Route::delete('/{id}/files/{fileId}', [SiteRequestController::class, 'deleteFile'])
            ->middleware('authorize:site_requests.edit')
            ->name('delete_file');

        Route::post('/{id}/status', [SiteRequestController::class, 'changeStatus'])
            ->middleware('authorize:site_requests.change_status')
            ->name('change_status');
        Route::post('/{id}/assign', [SiteRequestController::class, 'assign'])
            ->middleware('authorize:site_requests.assign')
            ->name('assign');
        Route::post('/{id}/submit', [SiteRequestController::class, 'submit'])
            ->middleware('authorize:site_requests.edit')
            ->name('submit');
        Route::post('/groups/{id}/submit', [SiteRequestController::class, 'submitGroup'])
            ->middleware('authorize:site_requests.edit')
            ->name('submit_group');
    });

Route::prefix('api/v1/mobile/site-requests')
    ->name('mobile.site_requests.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'site_requests.active'])
    ->group(function () {
        Route::get('/templates', [MobileSiteRequestController::class, 'templates'])->name('templates');
        Route::post('/from-template/{templateId}', [MobileSiteRequestController::class, 'createFromTemplate'])->name('from_template');

        Route::get('/calendar', [MobileSiteRequestController::class, 'calendar'])->name('calendar');
        Route::get('/meta', [MobileSiteRequestController::class, 'meta'])->name('meta');

        Route::get('/', [MobileSiteRequestController::class, 'index'])->name('index');
        Route::post('/', [MobileSiteRequestController::class, 'store'])->name('store');
        Route::put('/groups/{id}', [MobileSiteRequestController::class, 'updateGroup'])->name('update_group');
        Route::get('/{id}', [MobileSiteRequestController::class, 'show'])->name('show');
        Route::put('/{id}', [MobileSiteRequestController::class, 'update'])->name('update');

        Route::post('/{id}/status', [MobileSiteRequestController::class, 'changeStatus'])->name('change_status');
        Route::post('/{id}/submit', [MobileSiteRequestController::class, 'submit'])->name('submit');
        Route::post('/{id}/cancel', [MobileSiteRequestController::class, 'cancel'])->name('cancel');
        Route::post('/{id}/complete', [MobileSiteRequestController::class, 'complete'])->name('complete');
    });
