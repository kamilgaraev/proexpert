<?php

declare(strict_types=1);

use App\BusinessModules\Features\Crm\Http\Controllers\CrmController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/crm')
    ->name('admin.crm.')
    ->middleware(AdminRouteStack::middleware(['module.access:crm']))
    ->group(function (): void {
        Route::get('/summary', [CrmController::class, 'summary'])
            ->middleware('authorize:crm.analytics.view')
            ->name('summary');
        Route::get('/references', [CrmController::class, 'references'])
            ->middleware('authorize:crm.view')
            ->name('references');

        Route::get('/duplicates/{entityType}', [CrmController::class, 'duplicateCandidates'])
            ->middleware('authorize:crm.merge.view')
            ->name('duplicates.index');
        Route::post('/merge', [CrmController::class, 'merge'])
            ->middleware('authorize:crm.merge.execute')
            ->name('merge');

        Route::post('/imports/preview', [CrmController::class, 'importPreview'])
            ->middleware('authorize:crm.import.preview')
            ->name('imports.preview');
        Route::get('/imports/{batchId}', [CrmController::class, 'importShow'])
            ->middleware('authorize:crm.import.preview')
            ->name('imports.show');
        Route::get('/imports/{batchId}/rows', [CrmController::class, 'importRows'])
            ->middleware('authorize:crm.import.preview')
            ->name('imports.rows');
        Route::post('/imports/{batchId}/confirm', [CrmController::class, 'importConfirm'])
            ->middleware('authorize:crm.import.confirm')
            ->name('imports.confirm');
        Route::post('/imports/{batchId}/cancel', [CrmController::class, 'importCancel'])
            ->middleware('authorize:crm.import.preview')
            ->name('imports.cancel');

        Route::get('/timeline/{entityType}/{entityId}', [CrmController::class, 'timeline'])
            ->middleware('authorize:crm.timeline.view')
            ->name('timeline.index');

        Route::prefix('companies')->name('companies.')->group(function (): void {
            Route::get('/', [CrmController::class, 'companies'])->middleware('authorize:crm.companies.view')->name('index');
            Route::post('/', [CrmController::class, 'storeCompany'])->middleware('authorize:crm.companies.create')->name('store');
            Route::get('/{id}', [CrmController::class, 'showCompany'])->middleware('authorize:crm.companies.view')->name('show');
            Route::patch('/{id}', [CrmController::class, 'updateCompany'])->middleware('authorize:crm.companies.update')->name('update');
            Route::delete('/{id}', [CrmController::class, 'archiveCompany'])->middleware('authorize:crm.companies.archive')->name('archive');
            Route::post('/{id}/restore', [CrmController::class, 'restoreCompany'])->middleware('authorize:crm.companies.restore')->name('restore');
        });

        Route::prefix('contacts')->name('contacts.')->group(function (): void {
            Route::get('/', [CrmController::class, 'contacts'])->middleware('authorize:crm.contacts.view')->name('index');
            Route::post('/', [CrmController::class, 'storeContact'])->middleware('authorize:crm.contacts.create')->name('store');
            Route::get('/{id}', [CrmController::class, 'showContact'])->middleware('authorize:crm.contacts.view')->name('show');
            Route::patch('/{id}', [CrmController::class, 'updateContact'])->middleware('authorize:crm.contacts.update')->name('update');
            Route::delete('/{id}', [CrmController::class, 'archiveContact'])->middleware('authorize:crm.contacts.archive')->name('archive');
            Route::post('/{id}/restore', [CrmController::class, 'restoreContact'])->middleware('authorize:crm.contacts.restore')->name('restore');
        });

        Route::prefix('leads')->name('leads.')->group(function (): void {
            Route::get('/', [CrmController::class, 'leads'])->middleware('authorize:crm.leads.view')->name('index');
            Route::post('/', [CrmController::class, 'storeLead'])->middleware('authorize:crm.leads.create')->name('store');
            Route::get('/{id}', [CrmController::class, 'showLead'])->middleware('authorize:crm.leads.view')->name('show');
            Route::patch('/{id}', [CrmController::class, 'updateLead'])->middleware('authorize:crm.leads.update')->name('update');
            Route::post('/{id}/qualify', [CrmController::class, 'qualifyLead'])->middleware('authorize:crm.leads.update')->name('qualify');
            Route::post('/{id}/convert', [CrmController::class, 'convertLead'])->middleware('authorize:crm.leads.convert')->name('convert');
            Route::delete('/{id}', [CrmController::class, 'archiveLead'])->middleware('authorize:crm.leads.archive')->name('archive');
            Route::post('/{id}/restore', [CrmController::class, 'restoreLead'])->middleware('authorize:crm.leads.restore')->name('restore');
        });

        Route::prefix('deals')->name('deals.')->group(function (): void {
            Route::get('/', [CrmController::class, 'deals'])->middleware('authorize:crm.deals.view')->name('index');
            Route::post('/', [CrmController::class, 'storeDeal'])->middleware('authorize:crm.deals.create')->name('store');
            Route::get('/{id}', [CrmController::class, 'showDeal'])->middleware('authorize:crm.deals.view')->name('show');
            Route::patch('/{id}', [CrmController::class, 'updateDeal'])->middleware('authorize:crm.deals.update')->name('update');
            Route::post('/{id}/stage', [CrmController::class, 'transitionDeal'])->middleware('authorize:crm.deals.stage')->name('stage');
            Route::post('/{id}/links', [CrmController::class, 'linkDeal'])->middleware('authorize:crm.deals.link')->name('links');
            Route::delete('/{id}', [CrmController::class, 'archiveDeal'])->middleware('authorize:crm.deals.archive')->name('archive');
            Route::post('/{id}/restore', [CrmController::class, 'restoreDeal'])->middleware('authorize:crm.deals.restore')->name('restore');
        });

        Route::prefix('activities')->name('activities.')->group(function (): void {
            Route::get('/', [CrmController::class, 'activities'])->middleware('authorize:crm.activities.view')->name('index');
            Route::post('/', [CrmController::class, 'storeActivity'])->middleware('authorize:crm.activities.create')->name('store');
            Route::get('/{id}', [CrmController::class, 'showActivity'])->middleware('authorize:crm.activities.view')->name('show');
            Route::patch('/{id}', [CrmController::class, 'updateActivity'])->middleware('authorize:crm.activities.update')->name('update');
            Route::post('/{id}/complete', [CrmController::class, 'completeActivity'])->middleware('authorize:crm.activities.complete')->name('complete');
            Route::delete('/{id}', [CrmController::class, 'archiveActivity'])->middleware('authorize:crm.activities.archive')->name('archive');
            Route::post('/{id}/restore', [CrmController::class, 'restoreActivity'])->middleware('authorize:crm.activities.restore')->name('restore');
        });
    });
