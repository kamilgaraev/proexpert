<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\OrganizationController;
use App\Http\Controllers\Api\V1\Landing\OrganizationVerificationController;

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'role:organization_owner|organization_admin'])
    ->prefix('organization')
    ->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])
             ->name('landing.organization.show');
        Route::patch('/', [OrganizationController::class, 'update'])
             ->name('landing.organization.update');
    });

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
    ->prefix('organization')
    ->group(function () {
        Route::get('/verification', [OrganizationVerificationController::class, 'show'])
             ->name('landing.organization.verification.show');
        Route::patch('/verification', [OrganizationVerificationController::class, 'update'])
             ->name('landing.organization.verification.update');
        Route::post('/verification/request', [OrganizationVerificationController::class, 'requestVerification'])
             ->name('landing.organization.verification.request');
    });

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing'])
    ->prefix('dadata')
    ->group(function () {
        Route::post('/suggest/organizations', [OrganizationVerificationController::class, 'suggestOrganizations'])
             ->name('landing.dadata.suggest.organizations');
        Route::post('/suggest/addresses', [OrganizationVerificationController::class, 'suggestAddresses'])
             ->name('landing.dadata.suggest.addresses');
        Route::post('/clean/address', [OrganizationVerificationController::class, 'cleanAddress'])
             ->name('landing.dadata.clean.address');
    }); 