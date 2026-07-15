<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Landing\ModuleController;
use App\Http\Controllers\Api\V1\Landing\OrganizationPackageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing', 'jwt.auth', 'verified', 'organization.context'])
    ->prefix('modules')
    ->name('modules.')
    ->group(function (): void {
        Route::get('/', [ModuleController::class, 'index'])->name('index');
        Route::get('/overview', [ModuleController::class, 'overview'])->name('overview');
        Route::get('/active', [ModuleController::class, 'active'])->name('active');
        Route::post('/check-access', [ModuleController::class, 'checkAccess'])->name('check-access');
        Route::get('/permissions', [ModuleController::class, 'permissions'])->name('permissions');
        Route::get('/bundled', [ModuleController::class, 'getBundledModules'])->name('bundled');
    });

Route::middleware(['auth:api_landing', 'jwt.auth', 'verified', 'organization.context'])
    ->prefix('packages')
    ->name('packages.')
    ->group(function (): void {
        Route::get('/', [OrganizationPackageController::class, 'index'])
            ->middleware(['interface:lk', 'authorize:billing.view'])
            ->name('index');

        Route::post('/{packageSlug}/trial', [OrganizationPackageController::class, 'startTrial'])
            ->middleware(['interface:lk', 'authorize:billing.manage'])
            ->name('trial');
    });
