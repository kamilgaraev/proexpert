<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileCompanionModuleController;
use App\Http\Controllers\Api\V1\Mobile\MobileCatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->where(['module' => '[a-z0-9-]+', 'action' => '[a-z0-9_-]+'])
    ->group(function (): void {
        Route::get('/companions/{module}', [MobileCompanionModuleController::class, 'index'])->name('companions.index');
        Route::get('/companions/{module}/{id}', [MobileCompanionModuleController::class, 'show'])->whereNumber('id')->name('companions.show');
        Route::post('/companions/{module}/{id}/actions/{action}', [MobileCompanionModuleController::class, 'action'])->whereNumber('id')->name('companions.action');
        Route::get('/catalog/crm/{entity}', [MobileCatalogController::class, 'crmIndex'])->whereIn('entity', ['companies', 'contacts', 'leads', 'deals', 'activities'])->name('catalog.crm.index');
        Route::post('/catalog/crm/activities', [MobileCatalogController::class, 'crmStoreActivity'])->name('catalog.crm.activities.store');
        Route::get('/catalog/crm/{entity}/{id}', [MobileCatalogController::class, 'crmShow'])->whereIn('entity', ['companies', 'contacts', 'leads', 'deals', 'activities'])->name('catalog.crm.show');
        Route::get('/catalog/tenders', [MobileCatalogController::class, 'tenderIndex'])->name('catalog.tenders.index');
        Route::get('/catalog/tenders/{id}', [MobileCatalogController::class, 'tenderShow'])->name('catalog.tenders.show');
        Route::get('/catalog/reports', [MobileCatalogController::class, 'reportsIndex'])->name('catalog.reports.index');
        Route::get('/catalog/reports/{id}', [MobileCatalogController::class, 'reportShow'])->name('catalog.reports.show');
        Route::get('/catalog/templates', [MobileCatalogController::class, 'templatesIndex'])->name('catalog.templates.index');
        Route::get('/catalog/templates/{id}', [MobileCatalogController::class, 'templateShow'])->whereNumber('id')->name('catalog.templates.show');
    });
