<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\DashboardController;

// -----------------------------------------------------------------------------
// Дашборд личного кабинета (ЛК)
// -----------------------------------------------------------------------------
// Здесь собираются маршруты, которые отдают сводную информацию по текущей
// организации пользователя. Модуль мультиорганизации не используется.
// -----------------------------------------------------------------------------

Route::middleware(['auth:api_landing', 'organization.context'])
    ->name('dashboard.')
    ->group(function () {
        // Главная сводка дашборда ЛК
        // GET /api/v1/landing/dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('index');

        // В будущем здесь можно добавить дополнительные эндпойнты, например:
        // Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('stats');
        // Route::get('/dashboard/history', [DashboardController::class, 'history'])->name('history');
    }); 