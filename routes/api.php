<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdvanceAccountTransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\Landing\MultiOrganizationController;
use App\Http\Controllers\Api\V1\Landing\UserInvitationController;
use App\Http\Controllers\Api\V1\HoldingApiController;
use App\Http\Controllers\Api\V1\System\GlitchTipController;
use App\Http\Controllers\Api\V1\Webhook\YooKassaWebhookController;
use App\Http\Controllers\Api\V1\Admin\LegalDocumentEditorController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded from bootstrap/app.php and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Если вам нужно добавить новый маршрут API, добавьте его в соответствующий файл:
// - Для админки: routes/api/v1/admin/...
// - Для мобильного приложения: routes/api/v1/mobile/...
// - Для лендинга/ЛК: routes/api/v1/landing/...

Route::prefix('public')->name('api.public.')->group(function () {
    require __DIR__ . '/api/public.php';
});

Route::prefix('v1/holding-api')->name('api.v1.holdingApi.')->group(function () {
    require __DIR__ . '/api/v1/holding-api.php';
});

Route::prefix('v1/blog')->name('api.v1.blog.')->group(function () {
    require __DIR__ . '/api/v1/blog_public.php';
});

// Начинаем группу маршрутов API v1
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('webhooks/yookassa', YooKassaWebhookController::class)->name('webhooks.yookassa');


Route::prefix('landing')->name('landing.')->group(function () {
    // Явно включаем маршруты для Landing API
    require __DIR__ . '/api/v1/landing/auth.php';
    require __DIR__ . '/api/v1/landing/security.php';
    require __DIR__ . '/api/v1/landing/users.php';
    
    require __DIR__ . '/api/v1/landing/organization.php';

    Route::prefix('user-management')
        ->name('userManagement.')
        ->group(function () {
            Route::get('/invitation/{token}', [UserInvitationController::class, 'getByToken'])->name('invitation.get');
            Route::post('/invitation/{token}/accept', [UserInvitationController::class, 'accept'])->name('invitation.accept');
        });

    // Подключение маршрутов биллинга для лендинга/ЛК
    // Эти маршруты должны быть доступны аутентифицированному владельцу организации
    Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context'])
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

            require __DIR__ . '/api/v1/landing/holding.php';

            // Роуты авторизации Landing Admins
            require __DIR__ . '/api/v1/landing/landing_admin_auth.php';

            // Дашборд личного кабинета
            require __DIR__ . '/api/v1/landing/dashboard.php';
            
            // Подключение маршрутов мультиорганизации для лендинга/ЛК
            require __DIR__ . '/api/v1/landing/multi_organization.php';
            
            // Organization Profile & Capabilities Management
            require __DIR__ . '/api/v1/landing/organization-profile.php';

            require __DIR__ . '/api/v1/landing/support.php';

            require __DIR__ . '/api/v1/landing/knowledge_hub.php';
            
            // Подключение маршрутов приглашений подрядчиков для лендинга/ЛК
            Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])
                ->group(function () {
                    if (file_exists(__DIR__ . '/api/v1/landing/contractor_invitations.php')) {
                        require __DIR__ . '/api/v1/landing/contractor_invitations.php';
                    }
                    if (file_exists(__DIR__ . '/api/v1/landing/contractor_marketplace.php')) {
                        require __DIR__ . '/api/v1/landing/contractor_marketplace.php';
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

Route::prefix('v1/customer')->name('customer.v1.')->group(function () {
    require __DIR__ . '/api/v1/customer.php';
});

Route::prefix('customer')->name('customer.')->group(function () {
    require __DIR__ . '/api/v1/customer.php';
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

Route::prefix('v1/brigades')->name('brigades.')->group(function () {
    require __DIR__ . '/api/v1/brigades.php';
});

Route::prefix('v1/mobile')->name('api.v1.mobile.')->group(function () {
    require __DIR__ . '/api/v1/mobile/auth.php';
    require __DIR__ . '/api/v1/mobile/security.php';
    require __DIR__ . '/api/v1/mobile/dashboard.php';
    require __DIR__ . '/api/v1/mobile/modules.php';
    require __DIR__ . '/api/v1/mobile/companions.php';
    require __DIR__ . '/api/v1/mobile/knowledge_hub.php';
    require __DIR__ . '/api/v1/mobile/projects.php';
    require __DIR__ . '/api/v1/mobile/warehouse.php';
    require __DIR__ . '/api/v1/mobile/schedule.php';
    require __DIR__ . '/api/v1/mobile/notifications.php';

    if (file_exists(__DIR__ . '/api/v1/mobile/construction_journal.php')) {
        require __DIR__ . '/api/v1/mobile/construction_journal.php';
    }
});

// --- Admin Panel API ---
Route::post('v1/legal-document-editor/callback/{session}', [LegalDocumentEditorController::class, 'callback'])
    ->middleware([\App\Http\Middleware\OnlyOfficeCallbackBodyLimit::class, 'throttle:legal-editor-callback'])
    ->withoutMiddleware(['throttle:api', \App\Http\Middleware\SetOrganizationContext::class])
    ->whereUuid('session')
    ->name('api.v1.legal-document-editor.callback');

Route::prefix('v1/admin')->middleware('admin.response')->name('admin.')->group(function () {
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
        require __DIR__ . '/api/v1/admin/geocoding.php';
        // Подключаем существующие файлы маршрутов для админки
        require __DIR__ . '/api/v1/admin/projects.php';
        require __DIR__ . '/api/v1/admin/catalogs.php';
        require __DIR__ . '/api/v1/admin/mdm.php';
        require __DIR__ . '/api/v1/admin/users.php';
        require __DIR__ . '/api/v1/admin/logs.php';
        require __DIR__ . '/api/v1/admin/activity.php';
        require __DIR__ . '/api/v1/admin/immutable_audit.php';
        require __DIR__ . '/api/v1/admin/access_recertification.php';
        require __DIR__ . '/api/v1/admin/erp_controls.php';
        require __DIR__ . '/api/v1/admin/legal_archive.php';
        require __DIR__ . '/api/v1/admin/reports.php';
        require __DIR__ . '/api/v1/admin/report_templates.php';
        // Подключаем маршруты для подотчетных средств
        require __DIR__ . '/api/v1/admin/advance_transactions.php';
        require __DIR__ . '/api/v1/admin/one_c_exchange.php';
        // Подключаем маршруты для коэффициентов норм расхода
        require __DIR__ . '/api/v1/admin/rate_coefficients.php';
        
        // Контракты (включая allocations)
        require __DIR__ . '/api/v1/admin/contracts.php';
        
        // Общий журнал работ (ОЖР, форма КС-6)
        require __DIR__ . '/api/construction-journal.php';
        
        // OLD ROUTES - Закомментированы для Project-Based RBAC
        // Используйте новые маршруты: /api/v1/admin/projects/{project}/...
        // require __DIR__ . '/api/v1/admin/agreements.php';
        
        // Сюда можно будет добавлять require для новых файлов маршрутов админки
        require __DIR__ . '/api/v1/admin/dashboard.php';
        require __DIR__ . '/api/v1/admin/knowledge_hub.php';
        require __DIR__ . '/api/v1/admin/profile.php';
        require __DIR__ . '/api/v1/admin/security.php';
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
        require __DIR__ . '/api/v1/admin/counterparties.php';
        require __DIR__ . '/api/v1/admin/contractors.php';
        require __DIR__ . '/api/v1/admin/contractor_invitations.php';
        require __DIR__ . '/api/v1/admin/brigades.php';
        require __DIR__ . '/api/v1/admin/estimates.php';
        require __DIR__ . '/api/v1/admin/schedules.php';
        
        // PROJECT-BASED ROUTES with ProjectContext Middleware
        require __DIR__ . '/api/v1/admin/project-based.php';
    });

    Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])->group(function () {
        if (file_exists(__DIR__ . '/api/v1/admin/error-tracking.php')) {
            require __DIR__ . '/api/v1/admin/error-tracking.php';
        }

        if (file_exists(__DIR__ . '/api/estimates-enterprise.php')) {
            require __DIR__ . '/api/estimates-enterprise.php';
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
    require __DIR__ . '/api/v1/mobile/security.php';

    // Защищенные маршруты Mobile App (требуют токен mobile + контекст организации)
    Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])->group(function() {
        
        // Маршруты для проверки прав пользователя (Mobile App)
        Route::prefix('v1')->group(function () {
            if (file_exists(__DIR__ . '/api/v1/permissions.php')) {
                require __DIR__ . '/api/v1/permissions.php';
            }
        });
        
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

Route::prefix('v1/system/glitchtip')->name('system.glitchtip.')->group(function () {
    Route::post('webhook', [GlitchTipController::class, 'webhook'])->name('webhook');
    Route::get('status', [GlitchTipController::class, 'status'])->name('status');
    Route::get('issues', [GlitchTipController::class, 'issues'])->name('issues');
    Route::post('pull-request', [GlitchTipController::class, 'pullRequest'])->name('pull-request');
});
