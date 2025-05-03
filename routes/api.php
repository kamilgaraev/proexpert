<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Применяем общие middleware для всех API v1, если нужно (например, throttle)
// Route::middleware('throttle:api')->group(function() { // Раскомментировать, если нужно

    Route::prefix('v1')->name('api.v1.')->group(function () {

        // --- Landing/LK API ---
        Route::prefix('landing')->name('landing.')->group(function () {
            // Публичные маршруты Landing (Auth)
            require __DIR__ . '/api/v1/landing/auth.php';

            // Защищенные маршруты Landing (требуют токен landing + контекст организации)
            Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context'])->group(function() {
                require __DIR__ . '/api/v1/landing/users.php'; // Управление админами из ЛК
                // Проверяем существование файла перед подключением
                if (file_exists(__DIR__ . '/api/v1/landing/admin_panel_users.php')) {
                    require __DIR__ . '/api/v1/landing/admin_panel_users.php'; // Управление пользователями админки из ЛК
                }
                require __DIR__ . '/api/v1/landing/organization.php';
                require __DIR__ . '/api/v1/landing/support.php';
                // Добавить другие защищенные маршруты ЛК
            });
        });

        // --- Admin Panel API ---
        Route::prefix('admin')->name('admin.')->group(function () {
            // Публичные маршруты Admin Panel (Auth)
            require __DIR__ . '/api/v1/admin/auth.php';

            // Защищенные маршруты Admin Panel (требуют токен admin + контекст организации)
            Route::middleware(['auth:api_admin', 'jwt.auth', 'organization.context'])->group(function() {
                require __DIR__ . '/api/v1/admin/projects.php';
                require __DIR__ . '/api/v1/admin/materials.php';
                require __DIR__ . '/api/v1/admin/work_types.php';
                require __DIR__ . '/api/v1/admin/suppliers.php';
                require __DIR__ . '/api/v1/admin/users.php'; // Управление прорабами
                // Добавить другие защищенные маршруты админки
            });
        });

        // --- Mobile App API ---
        Route::prefix('mobile')->name('mobile.')->group(function () {
            // Публичные маршруты Mobile App (Auth)
            require __DIR__ . '/api/v1/mobile/auth.php';

            // Защищенные маршруты Mobile App (требуют токен mobile + контекст организации)
            Route::middleware(['auth:api_mobile', 'jwt.auth', 'organization.context'])->group(function() {
                // require __DIR__ . '/api/v1/mobile/tasks.php'; // Пример
                // Добавить другие защищенные маршруты мобильного приложения
            });
        });

    });

// }); // Конец группы throttle:api