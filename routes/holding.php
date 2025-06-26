<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldingController;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;

Route::get('/', [HoldingController::class, 'index'])->name('holding.home');

Route::middleware(['auth:api_landing', 'jwt.auth'])->group(function () {
    
    Route::get('/dashboard', [HoldingController::class, 'dashboard'])->name('holding.dashboard');
    
    Route::get('/organizations', [HoldingController::class, 'childOrganizations'])->name('holding.organizations');
    
    Route::prefix('api')->group(function () {
        
        Route::get('/hierarchy', [MultiOrganizationController::class, 'getHierarchy'])->name('holding.api.hierarchy');
        
        Route::get('/organization/{organizationId}', [MultiOrganizationController::class, 'getOrganizationData'])->name('holding.api.organization');
        
        Route::middleware(['role:organization_owner'])->group(function () {
            Route::post('/add-child', [MultiOrganizationController::class, 'addChildOrganization'])->name('holding.api.addChild');
        });
    });
    
}); 