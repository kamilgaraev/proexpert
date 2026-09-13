<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignModelSetController;
use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignProjectModelCatalogController;
use App\BusinessModules\Features\DesignManagement\Http\Middleware\ThrottleDesignModelSessionEvents;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.bim.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/project-model-versions', DesignProjectModelCatalogController::class)
            ->middleware('authorize:design-management.models.view')->name('catalog.index');
        Route::get('/model-sessions', [DesignModelSetController::class, 'sessions'])
            ->middleware('authorize:design-management.models.view')
            ->name('sessions.index');
        Route::get('/model-sets', [DesignModelSetController::class, 'index'])
            ->middleware('authorize:design-management.models.view')
            ->name('sets.index');
        Route::get('/model-sets/{setId}', [DesignModelSetController::class, 'show'])
            ->middleware('authorize:design-management.models.view')
            ->name('sets.show');
        Route::get('/model-sets/{setId}/revisions/{revision}/view', [DesignModelSetController::class, 'openRevision'])
            ->whereNumber(['setId', 'revision'])
            ->middleware('authorize:design-management.models.view')->name('sets.revisions.view');
        Route::post('/model-sets', [DesignModelSetController::class, 'store'])
            ->middleware('authorize:design-management.models.edit')
            ->name('sets.store');
        Route::put('/model-sets/{setId}', [DesignModelSetController::class, 'update'])
            ->middleware('authorize:design-management.models.edit')
            ->name('sets.update');
        Route::post('/model-sessions', [DesignModelSetController::class, 'storeSession'])
            ->middleware('authorize:design-management.models.view')
            ->name('sessions.store');
        Route::get('/model-sessions/{sessionId}/bootstrap', [DesignModelSetController::class, 'bootstrap'])
            ->middleware('authorize:design-management.models.view')
            ->name('sessions.bootstrap');
        Route::post('/model-sessions/{sessionId}/events', [DesignModelSetController::class, 'transientEvent'])
            ->middleware(['authorize:design-management.models.view', ThrottleDesignModelSessionEvents::class])
            ->name('sessions.events.store');
    });
