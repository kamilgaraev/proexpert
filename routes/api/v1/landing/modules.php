<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\OrganizationModuleController;

Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context'])
    ->prefix('modules')
    ->name('modules.')
    ->group(function () {
        Route::get('/', [OrganizationModuleController::class, 'index'])
            ->name('index');
        
        Route::get('/available', [OrganizationModuleController::class, 'available'])
            ->name('available');
        
        Route::get('/expiring', [OrganizationModuleController::class, 'expiring'])
            ->name('expiring');
        
        Route::post('/check-access', [OrganizationModuleController::class, 'checkAccess'])
            ->name('check-access');
            
        Route::middleware(['role:organization_owner'])
            ->group(function () {
                Route::post('/activate', [OrganizationModuleController::class, 'activate'])
                    ->name('activate');
                
                Route::delete('/{moduleId}', [OrganizationModuleController::class, 'deactivate'])
                    ->name('deactivate');
                
                Route::patch('/{moduleId}/renew', [OrganizationModuleController::class, 'renew'])
                    ->name('renew');
            });
    }); 