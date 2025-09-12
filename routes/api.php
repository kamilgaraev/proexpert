<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdvanceAccountTransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;
use App\Http\Controllers\Api\V1\HoldingApiController;

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

        // --- Mobile App API ---
        Route::prefix('mobile')->name('mobile.')->group(function () {
            // Публичные маршруты Mobile App (Auth)
            require __DIR__ . '/api/v1/mobile/auth.php';

            // Защищенные маршруты Mobile App (требуют токен mobile + контекст организации)
            // Middleware 'jwt.auth' и 'organization.context' + Gate 'access-mobile-app'
            Route::middleware(['auth:api_mobile', 'jwt.auth', 'organization.context', 'can:access-mobile-app'])->group(function() {
                require __DIR__ . '/api/v1/mobile/projects.php';
                require __DIR__ . '/api/v1/mobile/log.php';
                if (file_exists(__DIR__ . '/api/v1/mobile/catalogs.php')) {
                    require __DIR__ . '/api/v1/mobile/catalogs.php';
                }
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
Route::prefix('v1')->name('api.v1.')->group(function () {

Route::prefix('landing')->name('landing.')->group(function () {
    // Явно включаем маршруты для Landing API
    require __DIR__ . '/api/v1/landing/auth.php';
    // require __DIR__ . '/api/v1/landing/users.php'; // Закомментировал, т.к. ниже есть более специфичное подключение для adminPanelUsers
    
    require __DIR__ . '/api/v1/landing/organization.php';
    
    // Маршруты для управления пользователями админ-панели (accountant, web_admin)
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'authorize:users.manage_admin'])
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
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'authorize:billing.manage'])
        ->prefix('billing')
        ->name('billing.')
        ->group(function () {
            require __DIR__ . '/api/v1/landing/billing.php';
        });

                // Подключение маршрутов управления пользователями для лендинга/ЛК
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context', 'authorize:users.manage'])
                ->prefix('user-management')
                ->name('userManagement.')
                ->group(function () {
                    require __DIR__ . '/api/v1/landing/user_management.php';
                });

            // Подключение маршрутов модулей для лендинга/ЛК
            require __DIR__ . '/api/v1/landing/modules.php';

            // Подключение маршрутов Landing Admins
            require __DIR__ . '/api/v1/landing/landing_admins.php';

            // Роуты авторизации Landing Admins
            require __DIR__ . '/api/v1/landing/landing_admin_auth.php';

            // Дашборд личного кабинета
            require __DIR__ . '/api/v1/landing/dashboard.php';
            
            // Подключение маршрутов мультиорганизации для лендинга/ЛК
            require __DIR__ . '/api/v1/landing/multi_organization.php';
            
            // Подключение маршрутов приглашений подрядчиков для лендинга/ЛК
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
                ->group(function () {
                    if (file_exists(__DIR__ . '/api/v1/landing/contractor_invitations.php')) {
                        require __DIR__ . '/api/v1/landing/contractor_invitations.php';
                    }
                });

            // Подключение маршрутов новой системы авторизации
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
                ->group(function () {
                    require __DIR__ . '/api/v1/landing/authorization.php';
                });

            // Подключение маршрутов для проверки прав пользователя (ЛК)
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
                ->prefix('v1')
                ->group(function () {
                    if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
                        require __DIR__ . '/api/v1/permissions.php';
                    }
                });
});

// --- Admin Panel API ---
Route::prefix('admin')->name('admin.')->group(function () {
    // Публичные маршруты аутентификации админки
    require __DIR__ . '/api/v1/admin/auth.php';

    // Защищенные маршруты Admin Panel  
    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])->group(function() {
        
        // Маршруты для проверки прав пользователя (Admin Panel)
        Route::prefix('v1')->group(function () {
            if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
                require __DIR__ . '/api/v1/permissions.php';
            }
        });
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
        if (file_exists(__DIR__ . '/api/v1/admin/report_templates.php')) {
            require __DIR__ . '/api/v1/admin/report_templates.php';
        }
        // Подключаем маршруты для подотчетных средств
        if (file_exists(__DIR__ . '/api/v1/admin/advance_transactions.php')) {
            require __DIR__ . '/api/v1/admin/advance_transactions.php';
        }
        // Подключаем маршруты для коэффициентов норм расхода
        if (file_exists(__DIR__ . '/api/v1/admin/rate_coefficients.php')) {
            require __DIR__ . '/api/v1/admin/rate_coefficients.php';
        }
        // Подключаем маршруты для выполненных работ
        if (file_exists(__DIR__ . '/api/v1/admin/completed_works.php')) {
            require __DIR__ . '/api/v1/admin/completed_works.php';
        }
        // Сюда можно будет добавлять require для новых файлов маршрутов админки
        if (file_exists(__DIR__ . '/api/v1/admin/dashboard.php')) {
            require __DIR__ . '/api/v1/admin/dashboard.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/profile.php')) {
            require __DIR__ . '/api/v1/admin/profile.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/material_analytics.php')) {
            require __DIR__ . '/api/v1/admin/material_analytics.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/contracts.php')) {
            require __DIR__ . '/api/v1/admin/contracts.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/agreements.php')) {
            require __DIR__ . '/api/v1/admin/agreements.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/specifications.php')) {
            require __DIR__ . '/api/v1/admin/specifications.php';
        }

        if (file_exists(__DIR__ . '/api/v1/admin/filters.php')) {
            require __DIR__ . '/api/v1/admin/filters.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/personal_files.php')) {
            require __DIR__ . '/api/v1/admin/personal_files.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/report_files.php')) {
            require __DIR__ . '/api/v1/admin/report_files.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/act_reports.php')) {
            require __DIR__ . '/api/v1/admin/act_reports.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/advance_settings.php')) {
            require __DIR__ . '/api/v1/admin/advance_settings.php';
        }
        // Подключаем маршруты для учета времени
        if (file_exists(__DIR__ . '/api/v1/admin/time_tracking.php')) {
            require __DIR__ . '/api/v1/admin/time_tracking.php';
        }

        if (file_exists(__DIR__ . '/api/v1/admin/contractors.php')) {
            require __DIR__ . '/api/v1/admin/contractors.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/contractor_invitations.php')) {
            require __DIR__ . '/api/v1/admin/contractor_invitations.php';
        }
        if (file_exists(__DIR__ . '/api/v1/admin/site_requests.php')) {
            require __DIR__ . '/api/v1/admin/site_requests.php';
        }
        if (file_exists(__DIR__ . '/api/v1/schedule.php')) {
            require __DIR__ . '/api/v1/schedule.php';
        }
    });
});

// --- Mobile App API ---
Route::prefix('mobile')->name('mobile.')->group(function () {
    // Публичные маршруты Mobile App (Auth)
    // Убедитесь, что файл auth.php для мобильного API существует или создайте его
    if (file_exists(__DIR__ . '/api/v1/mobile/auth.php')) {
        require __DIR__ . '/api/v1/mobile/auth.php';
    }

    // Защищенные маршруты Mobile App (требуют токен mobile + контекст организации)
    Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function() {
        
        // Маршруты для проверки прав пользователя (Mobile App)
        Route::prefix('v1')->group(function () {
            if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
                require __DIR__ . '/api/v1/permissions.php';
            }
        });
        if (file_exists(__DIR__ . '/api/v1/mobile/projects.php')) {
            require __DIR__ . '/api/v1/mobile/projects.php';
        }
        if (file_exists(__DIR__ . '/api/v1/mobile/log.php')) {
            require __DIR__ . '/api/v1/mobile/log.php';
        }
        if (file_exists(__DIR__ . '/api/v1/mobile/catalogs.php')) {
            require __DIR__ . '/api/v1/mobile/catalogs.php';
        }
        // Подключаем маршруты выполненных работ для мобильного приложения
        if (file_exists(__DIR__ . '/api/v1/mobile/completed_works.php')) {
            require __DIR__ . '/api/v1/mobile/completed_works.php';
        }
        // Подключаем маршруты для заявок с объекта
        if (file_exists(__DIR__ . '/api/v1/mobile/site_requests.php')) {
            require __DIR__ . '/api/v1/mobile/site_requests.php';
        }
        // Подключаем маршруты для учета времени
        if (file_exists(__DIR__ . '/api/v1/mobile/time_tracking.php')) {
            require __DIR__ . '/api/v1/mobile/time_tracking.php';
        }
        // Добавить другие защищенные маршруты мобильного приложения
    });
});

// Все маршруты API для подотчетных средств перенесены в файл routes/api/v1/admin/advance_transactions.php

// --- SuperAdmin API ---
Route::prefix('superadmin')->name('superadmin.')->group(function () {
    // Публичные маршруты суперадминки (например, аутентификация)
    if (file_exists(__DIR__ . '/api/v1/superadmin/auth.php')) {
        require __DIR__ . '/api/v1/superadmin/auth.php';
    }
    // В будущем: защищённые маршруты суперадминки
    // Route::middleware(['auth:api_superadmin', 'superadmin.context'])->group(function() {
    //     // require __DIR__ . '/api/v1/superadmin/dashboard.php';
    // });
});

    // API роуты для фронтенда холдингов (используются ЛК сервером)
    Route::prefix('holding-api')->name('holdingApi.')->group(function () {
        
        // Публичные данные холдинга (без авторизации)
        Route::get('{slug}', [HoldingApiController::class, 'getPublicData'])->name('publicData');
        
        // Защищенные эндпоинты (требуют авторизации)
        Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])->group(function () {
            Route::get('{slug}/dashboard', [HoldingApiController::class, 'getDashboardData'])->name('dashboard');
            Route::get('{slug}/organizations', [HoldingApiController::class, 'getOrganizations'])->name('organizations');
            Route::get('{slug}/organization/{organizationId}', [HoldingApiController::class, 'getOrganizationData'])->name('organizationData');
            
            // Добавление дочерней организации (только владельцы)
            Route::middleware(['authorize:multi_organization.manage'])->group(function () {
                Route::post('{slug}/add-child', [MultiOrganizationController::class, 'addChildOrganization'])->name('addChild');
            });
        });
});

}); // Закрываем группу v1