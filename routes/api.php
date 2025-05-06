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

/*
// ВНИМАНИЕ: Этот файл закомментирован, так как маршруты API уже определены в RouteServiceProvider.php.
// Использование обоих определений приводило к дублированию маршрутов (api/v1/v1/...)

// Применяем общие middleware для всех API v1, если нужно (например, throttle)
// Route::middleware('throttle:api')->group(function() { // Раскомментировать, если нужно

    Route::prefix('v1')->name('api.v1.')->group(function () {

        // --- Landing/LK API ---
        Route::prefix('landing')->name('landing.')->group(function () {
            // Публичные маршруты Landing (Auth)
            require __DIR__ . '/api/v1/landing/auth.php';

            // Защищенные маршруты Landing (требуют токен landing + контекст организации)
            // Middleware 'jwt.auth' и 'organization.context' вероятно нужны здесь
            Route::middleware(['auth:api_landing', 'jwt.auth', 'organization.context'])->group(function() {
                require __DIR__ . '/api/v1/landing/users.php'; // Управление админами из ЛК
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
            // Middleware 'jwt.auth' и 'organization.context' + Gate 'access-admin-panel'
            Route::middleware(['auth:api_admin', 'jwt.auth', 'organization.context', 'can:access-admin-panel'])->group(function() {
                require __DIR__ . '/api/v1/admin/projects.php';
                require __DIR__ . '/api/v1/admin/catalogs.php'; // Новый общий путь
                require __DIR__ . '/api/v1/admin/users.php'; // Управление прорабами
                require __DIR__ . '/api/v1/admin/logs.php'; // Добавляем подключение логов
                require __DIR__ . '/api/v1/admin/reports.php'; // Добавляем подключение отчетов
                // Добавить другие защищенные маршруты админки
            });
        });

        // --- Mobile App API ---
        Route::prefix('mobile')->name('mobile.')->group(function () {
            // Публичные маршруты Mobile App (Auth)
            require __DIR__ . '/api/v1/mobile/auth.php';

            // Защищенные маршруты Mobile App (требуют токен mobile + контекст организации)
            // Middleware 'jwt.auth' и 'organization.context' + Gate 'access-mobile-app'
            Route::middleware(['auth:api_mobile', 'jwt.auth', 'organization.context', 'can:access-mobile-app'])->group(function() {
                require __DIR__ . '/api/v1/mobile/projects.php';
                require __DIR__ . '/api/v1/mobile/log.php';
                // Добавить другие защищенные маршруты мобильного приложения
            });
        });

        // --- Debug Route ---
        Route::get('/debug-user', function () {
            try {
                $userInstance = app()->make(App\Models\User::class);
                return response()->json([
                    'message' => 'Successfully resolved App\\Models\\User',
                    'class' => get_class($userInstance),
                    'instance_details' => $userInstance->toArray() // Покажет пустой массив, т.к. это новый экземпляр
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Failed to resolve App\\Models\\User',
                    'error_class' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ], 500);
            }
        });

    });

// }); // Конец группы throttle:api
*/

// Если вам нужно добавить новый маршрут API, добавьте его в соответствующий файл:
// - Для админки: routes/api/v1/admin/...
// - Для мобильного приложения: routes/api/v1/mobile/...
// - Для лендинга/ЛК: routes/api/v1/landing/...

// Начинаем группу маршрутов API v1
Route::prefix('landing')->name('landing.')->group(function () {
    // Явно включаем маршруты для Landing API
    require __DIR__ . '/api/v1/landing/auth.php';
    // require __DIR__ . '/api/v1/landing/users.php'; // Закомментировал, т.к. ниже есть более специфичное подключение для adminPanelUsers
    require __DIR__ . '/api/v1/landing/organization.php';
    
    // Маршруты для управления пользователями админ-панели (accountant, web_admin)
    Route::middleware(['auth:api_landing', 'role:organization_owner|organization_admin'])
        ->prefix('adminPanelUsers')
        ->name('adminPanelUsers.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Landing\AdminPanelUserController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\Landing\AdminPanelUserController::class, 'store']);
            Route::get('/{user}', [\App\Http\Controllers\Api\V1\Landing\AdminPanelUserController::class, 'show']);
            Route::put('/{user}', [\App\Http\Controllers\Api\V1\Landing\AdminPanelUserController::class, 'update']);
            Route::delete('/{user}', [\App\Http\Controllers\Api\V1\Landing\AdminPanelUserController::class, 'destroy']);
        });

    // Подключение маршрутов биллинга для лендинга/ЛК
    // Эти маршруты должны быть доступны аутентифицированному владельцу организации
    Route::middleware(['auth:api_landing', 'role:organization_owner']) // Примерный middleware, уточните права
        ->prefix('billing')
        ->name('billing.')
        ->group(function () {
            require __DIR__ . '/api/v1/landing/billing.php';
        });
});

// --- Admin Panel API ---
Route::prefix('admin')->name('admin.')->group(function () {
    // Если у вас есть отдельный файл для публичных маршрутов аутентификации админки, его можно подключить здесь, например:
    // require __DIR__ . '/api/v1/admin/auth.php';

    // Защищенные маршруты Admin Panel
    Route::middleware(['auth:api_admin', 'jwt.auth', 'organization.context', 'can:access-admin-panel'])->group(function() {
        // Подключаем существующие файлы маршрутов для админки
        if (file_exists(__DIR__ . '/api/v1/admin/projects.php')) {
            require __DIR__ . '/api/v1/admin/projects.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/catalogs.php')) {
            require __DIR__ . '/api/v1/admin/catalogs.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/users.php')) {
            require __DIR__ . '/api/v1/admin/users.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/logs.php')) {
            require __DIR__ . '/api/v1/admin/logs.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/reports.php')) {
            require __DIR__ . '/api/v1/admin/reports.php';
        }
        // Сюда можно будет добавлять require для новых файлов маршрутов админки
    });
});

// Опционально, если есть Mobile App API, его конфигурация может идти дальше
// --- Mobile App API ---
// Route::prefix('mobile')->name('mobile.')->group(function () { ... });