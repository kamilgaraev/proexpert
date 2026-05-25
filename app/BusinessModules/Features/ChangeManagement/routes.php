<?php

declare(strict_types=1);

use App\BusinessModules\Features\ChangeManagement\Http\Controllers\ChangeManagementController;
use App\BusinessModules\Features\ChangeManagement\Http\Controllers\Customer\ChangeManagementController as CustomerChangeManagementController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/change-management')
    ->name('admin.change_management.')
    ->middleware(AdminRouteStack::middleware(['change-management.active']))
    ->group(function (): void {
        Route::get('/rfis', [ChangeManagementController::class, 'rfis'])
            ->middleware('authorize:change-management.view');
        Route::post('/rfis', [ChangeManagementController::class, 'storeRfi'])
            ->middleware('authorize:change-management.rfi.create');
        Route::post('/rfis/{id}/send', [ChangeManagementController::class, 'sendRfi'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.rfi.create');
        Route::post('/rfis/{id}/answer', [ChangeManagementController::class, 'answerRfi'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.rfi.answer');
        Route::post('/rfis/{id}/accept', [ChangeManagementController::class, 'acceptRfi'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.rfi.answer');

        Route::get('/changes', [ChangeManagementController::class, 'changes'])
            ->middleware('authorize:change-management.view');
        Route::post('/changes', [ChangeManagementController::class, 'storeChange'])
            ->middleware('authorize:change-management.create');
        Route::post('/changes/{id}/submit', [ChangeManagementController::class, 'submitChange'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.edit');
        Route::post('/changes/{id}/impact', [ChangeManagementController::class, 'assessImpact'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.edit');
        Route::post('/changes/{id}/internal-review', [ChangeManagementController::class, 'startInternalReview'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.edit');
        Route::post('/changes/{id}/customer-review', [ChangeManagementController::class, 'startCustomerReview'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.change-orders.approve');
        Route::post('/changes/{id}/approve', [ChangeManagementController::class, 'approveChange'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.change-orders.approve');
        Route::post('/changes/{id}/variation-orders', [ChangeManagementController::class, 'storeVariationOrder'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.change-orders.create');
        Route::post('/changes/{id}/implement', [ChangeManagementController::class, 'implementChange'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.edit');
        Route::post('/changes/{id}/close', [ChangeManagementController::class, 'closeChange'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.change-orders.approve');

        Route::get('/claims', [ChangeManagementController::class, 'claims'])
            ->middleware('authorize:change-management.view');
        Route::post('/claims', [ChangeManagementController::class, 'storeClaim'])
            ->middleware('authorize:change-management.claims.create');
    });

Route::prefix('api/v1/customer/change-management')
    ->name('customer.change_management.')
    ->middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context', 'change-management.active'])
    ->group(function (): void {
        Route::get('/changes', [CustomerChangeManagementController::class, 'changes'])
            ->middleware('authorize:change-management.view');
        Route::post('/changes/{id}/approve', [CustomerChangeManagementController::class, 'approve'])
            ->whereNumber('id')
            ->middleware('authorize:change-management.customer-submit');
    });
