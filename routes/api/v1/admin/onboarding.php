<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\OnboardingDemoController;

/*
|--------------------------------------------------------------------------
| Onboarding Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления обучающим туром и демо-данными
|
*/

Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('onboarding')
    ->name('onboarding.')
    ->group(function () {
        // Удаление демо-данных обучающего тура
        Route::delete('/demo-data', [OnboardingDemoController::class, 'deleteDemoData'])->name('demo_data.delete');
    });

