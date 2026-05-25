<?php

declare(strict_types=1);

use App\BusinessModules\Features\HandoverAcceptance\Http\Controllers\Customer\HandoverAcceptanceController as CustomerHandoverAcceptanceController;
use App\BusinessModules\Features\HandoverAcceptance\Http\Controllers\HandoverAcceptanceController;
use App\BusinessModules\Features\HandoverAcceptance\Http\Controllers\Mobile\HandoverAcceptanceController as MobileHandoverAcceptanceController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/handover-acceptance')
    ->name('admin.handover_acceptance.')
    ->middleware(AdminRouteStack::middleware(['handover-acceptance.active']))
    ->group(function (): void {
        Route::get('/scopes', [HandoverAcceptanceController::class, 'index'])
            ->middleware('authorize:handover-acceptance.view');
        Route::post('/locations', [HandoverAcceptanceController::class, 'storeLocation'])
            ->middleware('authorize:handover-acceptance.create');
        Route::post('/scopes', [HandoverAcceptanceController::class, 'storeScope'])
            ->middleware('authorize:handover-acceptance.create');
        Route::post('/scopes/{scope}/checklists', [HandoverAcceptanceController::class, 'storeChecklist'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.edit');
        Route::post('/scopes/{scope}/sessions', [HandoverAcceptanceController::class, 'storeSession'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/scopes/{scope}/start', [HandoverAcceptanceController::class, 'start'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/scopes/{scope}/ready-for-reinspection', [HandoverAcceptanceController::class, 'readyForReinspection'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/scopes/{scope}/accept', [HandoverAcceptanceController::class, 'accept'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.approve');
        Route::post('/scopes/{scope}/handover', [HandoverAcceptanceController::class, 'handover'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.customer-sign');
        Route::post('/scopes/{scope}/reopen', [HandoverAcceptanceController::class, 'reopen'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.edit');
        Route::post('/sessions/{session}/findings', [HandoverAcceptanceController::class, 'storeFinding'])
            ->whereNumber('session')
            ->middleware('authorize:handover-acceptance.punch-list.create');
        Route::post('/findings/{finding}/resolve', [HandoverAcceptanceController::class, 'resolveFinding'])
            ->whereNumber('finding')
            ->middleware('authorize:handover-acceptance.punch-list.resolve');
        Route::post('/scopes/{scope}/package', [HandoverAcceptanceController::class, 'storePackage'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.edit');
        Route::post('/package-documents/{document}/approve', [HandoverAcceptanceController::class, 'approvePackageDocument'])
            ->whereNumber('document')
            ->middleware('authorize:handover-acceptance.edit');
    });

Route::prefix('api/v1/customer/handover-acceptance')
    ->name('customer.handover_acceptance.')
    ->middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context', 'handover-acceptance.active'])
    ->group(function (): void {
        Route::get('/scopes', [CustomerHandoverAcceptanceController::class, 'index'])
            ->middleware('authorize:handover-acceptance.view');
        Route::post('/scopes/{scope}/handover', [CustomerHandoverAcceptanceController::class, 'handover'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.customer-sign');
        Route::post('/scopes/{scope}/reject', [CustomerHandoverAcceptanceController::class, 'reject'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.reject');
    });

Route::prefix('api/v1/mobile/handover-acceptance')
    ->name('mobile.handover_acceptance.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'handover-acceptance.active'])
    ->group(function (): void {
        Route::get('/scopes', [MobileHandoverAcceptanceController::class, 'index'])
            ->middleware('authorize:handover-acceptance.view');
        Route::get('/scopes/{scope}', [MobileHandoverAcceptanceController::class, 'show'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.view');
        Route::post('/checklist-items/{item}/review', [MobileHandoverAcceptanceController::class, 'reviewChecklistItem'])
            ->whereNumber('item')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/sessions/{session}/findings', [MobileHandoverAcceptanceController::class, 'storeFinding'])
            ->whereNumber('session')
            ->middleware('authorize:handover-acceptance.punch-list.create');
        Route::post('/findings/{finding}/resolve', [MobileHandoverAcceptanceController::class, 'resolveFinding'])
            ->whereNumber('finding')
            ->middleware('authorize:handover-acceptance.punch-list.resolve');
        Route::post('/scopes/{scope}/ready-for-reinspection', [MobileHandoverAcceptanceController::class, 'readyForReinspection'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/scopes/{scope}/start', [MobileHandoverAcceptanceController::class, 'start'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.inspect');
        Route::post('/scopes/{scope}/accept', [MobileHandoverAcceptanceController::class, 'accept'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.approve');
        Route::post('/scopes/{scope}/handover', [MobileHandoverAcceptanceController::class, 'handover'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.customer-sign');
        Route::post('/scopes/{scope}/reject', [MobileHandoverAcceptanceController::class, 'reject'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.reject');
        Route::post('/scopes/{scope}/reopen', [MobileHandoverAcceptanceController::class, 'reopen'])
            ->whereNumber('scope')
            ->middleware('authorize:handover-acceptance.reject');
        Route::post('/package-documents/{document}/upload', [MobileHandoverAcceptanceController::class, 'uploadPackageDocument'])
            ->whereNumber('document')
            ->middleware('authorize:handover-acceptance.submit');
    });
