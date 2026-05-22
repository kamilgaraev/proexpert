<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileCompanionModuleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->where(['module' => '[a-z0-9-]+', 'action' => '[a-z0-9_-]+'])
    ->group(function (): void {
        Route::get('/companions/{module}', [MobileCompanionModuleController::class, 'index'])->name('companions.index');
        Route::get('/companions/{module}/{id}', [MobileCompanionModuleController::class, 'show'])->whereNumber('id')->name('companions.show');
        Route::post('/companions/{module}/{id}/actions/{action}', [MobileCompanionModuleController::class, 'action'])->whereNumber('id')->name('companions.action');
    });
