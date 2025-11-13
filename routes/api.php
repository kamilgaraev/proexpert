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
                
                // Organization Profile & Capabilities Management
                require __DIR__ . '/api/v1/landing/organization-profile.php';
                
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
    require __DIR__ . '/api/v1/landing/users.php';
    
    require __DIR__ . '/api/v1/landing/organization.php';

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
            
            // Organization Profile & Capabilities Management
            require __DIR__ . '/api/v1/landing/organization-profile.php';
            
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

            // Подключение маршрутов уведомлений для ЛК
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
                ->group(function () {
                    require __DIR__ . '/api/v1/landing/notifications.php';
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
});

// --- LK API (Personal Cabinet) ---
Route::prefix('lk')->name('lk.')->group(function () {
    // Подключение маршрутов для проверки прав пользователя (ЛК)
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
        ->prefix('v1')
        ->group(function () {
            if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
                require __DIR__ . '/api/v1/permissions.php';
            }
        });
});

// --- Contractor Verification API (Public by token) ---
Route::prefix('v1')->group(function () {
    require __DIR__ . '/api/v1/contractor-verification.php';
});

// --- Admin Panel API ---
Route::prefix('v1/admin')->name('admin.')->group(function () {
    // Публичные маршруты аутентификации админки
    require __DIR__ . '/api/v1/admin/auth.php';
    
    // Маршрут для выбора проекта (БЕЗ project context, но с organization context)
    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])
        ->get('/available-projects', [\App\Http\Controllers\Api\V1\Admin\ProjectSelectorController::class, 'availableProjects'])
        ->name('projects.available');

    // Защищенные маршруты Admin Panel  
    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin'])->group(function() {
        
        // Маршруты для проверки прав пользователя (Admin Panel)
        if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
            require __DIR__ . '/api/v1/permissions.php';
        }
        // Подключаем маршруты для уведомлений
        require __DIR__ . '/api/v1/admin/notifications.php';
        // Подключаем существующие файлы маршрутов для админки
        require __DIR__ . '/api/v1/admin/projects.php';
        require __DIR__ . '/api/v1/admin/catalogs.php';
        require __DIR__ . '/api/v1/admin/users.php';
        require __DIR__ . '/api/v1/admin/logs.php';
        require __DIR__ . '/api/v1/admin/reports.php';
        require __DIR__ . '/api/v1/admin/report_templates.php';
        // Подключаем маршруты для подотчетных средств
        require __DIR__ . '/api/v1/admin/advance_transactions.php';
        // Подключаем маршруты для коэффициентов норм расхода
        require __DIR__ . '/api/v1/admin/rate_coefficients.php';
        
        // OLD ROUTES - Закомментированы для Project-Based RBAC
        // Используйте новые маршруты: /api/v1/admin/projects/{project}/...
        // require __DIR__ . '/api/v1/admin/completed_works.php';
        // require __DIR__ . '/api/v1/admin/contracts.php';
        // require __DIR__ . '/api/v1/admin/agreements.php';
        
        // Сюда можно будет добавлять require для новых файлов маршрутов админки
        require __DIR__ . '/api/v1/admin/dashboard.php';
        require __DIR__ . '/api/v1/admin/profile.php';
        require __DIR__ . '/api/v1/admin/onboarding.php';
        require __DIR__ . '/api/v1/admin/material_analytics.php';
        require __DIR__ . '/api/v1/admin/specifications.php';

        require __DIR__ . '/api/v1/admin/filters.php';
        require __DIR__ . '/api/v1/admin/personal_files.php';
        require __DIR__ . '/api/v1/admin/report_files.php';
        require __DIR__ . '/api/v1/admin/act_reports.php';
        require __DIR__ . '/api/v1/admin/act_files.php';
        require __DIR__ . '/api/v1/admin/advance_settings.php';
        // Подключаем маршруты для учета времени
        require __DIR__ . '/api/v1/admin/time_tracking.php';
        require __DIR__ . '/api/v1/admin/contractors.php';
        require __DIR__ . '/api/v1/admin/contractor_invitations.php';
        require __DIR__ . '/api/v1/admin/site_requests.php';
        require __DIR__ . '/api/v1/admin/custom-reports.php';
        require __DIR__ . '/api/v1/admin/advanced_dashboard.php';
        require __DIR__ . '/api/v1/admin/estimates.php';
        
        // PROJECT-BASED ROUTES with ProjectContext Middleware
        require __DIR__ . '/api/v1/admin/project-based.php';
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