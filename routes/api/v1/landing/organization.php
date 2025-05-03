<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\OrganizationController;

Route::middleware(['auth:api_landing', 'role:organization_owner|organization_admin']) // Используем | как разделитель
    ->prefix('organization')
    ->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])
             ->name('landing.organization.show'); // Имя маршрута
        Route::patch('/', [OrganizationController::class, 'update'])
             ->name('landing.organization.update'); // Имя маршрута
    }); 