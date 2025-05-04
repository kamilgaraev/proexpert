<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\ProjectController;

/*
|--------------------------------------------------------------------------
| Mobile API Project Routes
|--------------------------------------------------------------------------
|
| Маршруты, связанные с проектами для мобильного приложения.
|
*/

Route::middleware(['auth:api_mobile', 'can:access-mobile-app'])->group(function () {
    // Получение списка проектов, назначенных текущему прорабу
    Route::get('projects', [ProjectController::class, 'getAssignedProjects'])
        ->name('mobile.projects.index');

    // Маршрут для получения деталей конкретного проекта (если будет реализован)
    // Route::get('projects/{project}', [ProjectController::class, 'show'])
    //     ->name('mobile.projects.show');
}); 