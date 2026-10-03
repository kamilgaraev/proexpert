<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileDesignManagementController as Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->prefix('design-management')->name('design-management.')->group(function (): void {
        Route::get('packages', [Controller::class, 'packages'])->name('packages.index');
        Route::get('packages/{packageId}', [Controller::class, 'package'])->whereNumber('packageId')->name('packages.show');
        Route::get('project-model-versions', [Controller::class, 'versions'])->name('versions.index');
        Route::prefix('model-versions/{versionId}')->whereNumber('versionId')->group(function (): void {
            Route::get('viewer', [Controller::class, 'viewer'])->name('versions.viewer');
            Route::post('viewer/preparation', [Controller::class, 'prepare'])->name('versions.prepare');
            Route::get('offline-package', [Controller::class, 'offlinePackage'])->name('versions.offline-package');
            Route::get('elements', [Controller::class, 'elements'])->name('elements.index');
            Route::get('elements/{expressId}', [Controller::class, 'element'])->whereNumber('expressId')->name('elements.show');
        });
        Route::get('model-sets', [Controller::class, 'sets'])->name('sets.index');
        Route::post('model-sets', [Controller::class, 'storeSet'])->name('sets.store');
        Route::get('model-sets/{setId}', [Controller::class, 'showSet'])->whereNumber('setId')->name('sets.show');
        Route::patch('model-sets/{setId}', [Controller::class, 'updateSet'])->whereNumber('setId')->name('sets.update');
        Route::delete('model-sets/{setId}', [Controller::class, 'deleteSet'])->whereNumber('setId')->name('sets.destroy');
        Route::get('model-sets/{setId}/revisions/{revision}/open', [Controller::class, 'openSet'])->whereNumber(['setId', 'revision'])->name('sets.open');
        Route::get('project-issues', [Controller::class, 'issues'])->name('issues.index');
        Route::post('project-issues', [Controller::class, 'storeIssue'])->name('issues.store');
        Route::get('project-issues/assignees', [Controller::class, 'assignees'])->name('issues.assignees');
        Route::get('project-issues/{issueId}', [Controller::class, 'showIssue'])->whereNumber('issueId')->name('issues.show');
        Route::post('project-issues/{issueId}/actions/{action}', [Controller::class, 'issueAction'])->whereNumber('issueId')
            ->whereIn('action', ['assign', 'resolve', 'verify', 'blocking'])->name('issues.action');
        Route::post('project-issues/{issueId}/snapshot', [Controller::class, 'snapshot'])->whereNumber('issueId')->name('issues.snapshot');
        Route::post('project-issues/{issueId}/photos', [Controller::class, 'photo'])->whereNumber('issueId')->name('issues.photos');
        Route::get('project-issues/{issueId}/bim-context', [Controller::class, 'issueContext'])->whereNumber('issueId')->name('issues.context');
        Route::get('model-sessions', [Controller::class, 'sessions'])->name('sessions.index');
        Route::post('model-sessions', [Controller::class, 'storeSession'])->name('sessions.store');
        Route::get('model-sessions/{sessionId}/bootstrap', [Controller::class, 'bootstrap'])->whereNumber('sessionId')->name('sessions.bootstrap');
        Route::post('model-sessions/{sessionId}/events', [Controller::class, 'event'])->whereNumber('sessionId')
            ->middleware(\App\Http\Middleware\MobileDesignModelSessionThrottle::class)->name('sessions.events');
        Route::get('model-sessions/{sessionId}/participants', [Controller::class, 'participants'])->whereNumber('sessionId')->name('sessions.participants');
        Route::post('model-sessions/{sessionId}/view-state', [Controller::class, 'storeViewState'])->whereNumber('sessionId')
            ->middleware(\App\Http\Middleware\MobileDesignModelSessionThrottle::class)->name('sessions.view-state.store');
        Route::get('model-sessions/{sessionId}/view-state', [Controller::class, 'viewState'])->whereNumber('sessionId')->name('sessions.view-state.show');
        Route::get('model-sessions/{sessionId}/view-state/{clientId}', [Controller::class, 'viewState'])->whereNumber('sessionId')->name('sessions.view-state.client');
    });
