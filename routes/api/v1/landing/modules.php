<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\ModuleController;

Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context'])
    ->prefix('modules')
    ->name('modules.')
    ->group(function () {
        
        // Общедоступные методы (просмотр модулей)
        Route::get('/', [ModuleController::class, 'index'])
            ->name('index');
        
        Route::get('/active', [ModuleController::class, 'active'])
            ->name('active');
        
        Route::get('/expiring', [ModuleController::class, 'expiring'])
            ->name('expiring');
        
        Route::get('/{moduleSlug}/preview', [ModuleController::class, 'activationPreview'])
            ->name('activation-preview');
        
        Route::get('/{moduleSlug}/deactivation-preview', [ModuleController::class, 'deactivationPreview'])
            ->name('deactivation-preview');
        
        Route::post('/check-access', [ModuleController::class, 'checkAccess'])
            ->name('check-access');
        
        Route::get('/permissions', [ModuleController::class, 'permissions'])
            ->name('permissions');
        
        // Методы для владельцев организации
        Route::middleware(['authorize:modules.manage'])
            ->group(function () {
                
                Route::post('/activate', [ModuleController::class, 'activate'])
                    ->name('activate');
                
                Route::delete('/{moduleSlug}', [ModuleController::class, 'deactivate'])
                    ->name('deactivate');
                
                Route::patch('/{moduleSlug}/renew', [ModuleController::class, 'renew'])
                    ->name('renew');
                
                Route::post('/bulk-activate', [ModuleController::class, 'bulkActivate'])
                    ->name('bulk-activate');
            });
        
        // Биллинг информация (доступна владельцам и бухгалтерам)
        Route::middleware(['authorize:modules.billing'])
            ->prefix('billing')
            ->name('billing.')
            ->group(function () {
                
                Route::get('/', [ModuleController::class, 'billing'])
                    ->name('stats');
                
                Route::get('/history', [ModuleController::class, 'billingHistory'])
                    ->name('history');
            });
    });