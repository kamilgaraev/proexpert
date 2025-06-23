<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\SiteRequest;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Добавляем автоматическое обнаружение политик, если нужно
        // Если политики лежат в App\Policies и называются как ModelPolicy
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Настройка для безопасного режима входа в лендинг
        // ВАЖНО: УСТАНОВИТЕ ЭТОТ ПАРАМЕТР В .env для разблокировки доступа
        $landingSafetyMode = config('app.landing_safety_mode', true);
        if ($landingSafetyMode) {
            Log::warning('[AuthServiceProvider] Включен БЕЗОПАСНЫЙ РЕЖИМ доступа к лендингу!');
        }

        /**
         * Gate для доступа к Лендинг/ЛК API.
         * Требует роль Owner или Admin в текущей организации.
         */
        Gate::define('access-landing', function (User $user, ?int $organizationId = null) use ($landingSafetyMode): bool {
            // ВРЕМЕННЫЙ БЕЗОПАСНЫЙ РЕЖИМ - чтобы пользователи могли войти на проде
            if ($landingSafetyMode) {
                Log::warning('[Gate:access-landing] Доступ разрешен через БЕЗОПАСНЫЙ РЕЖИМ', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                return true;
            }
            
            // Используем ID организации из контекста пользователя, если не передан явно
            $orgId = $organizationId ?? $user->current_organization_id;
            
            // Подробное логирование для диагностики
            Log::info('[Gate:access-landing] Проверка доступа к лендингу', [
                'user_id' => $user->id,
                'email' => $user->email,
                'passed_org_id' => $organizationId,
                'user_current_org_id' => $user->current_organization_id,
                'effective_org_id' => $orgId
            ]);
            
            if (!$orgId) {
                Log::warning('[Gate:access-landing] Нет контекста организации', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                return false; // Невозможно определить контекст организации
            }
            
            // Проверяем через прямой SQL запрос, используя соединение таблиц
            try {
                // Получаем список всех ролей пользователя в данной организации
                $userRoles = DB::table('role_user')
                    ->join('roles', 'role_user.role_id', '=', 'roles.id')
                    ->where('role_user.user_id', $user->id)
                    ->where('role_user.organization_id', $orgId)
                    ->select('roles.slug', 'roles.name')
                    ->get();
                
                // Логируем все найденные роли
                Log::info('[Gate:access-landing] Роли пользователя в организации через SQL', [
                    'user_id' => $user->id, 
                    'organization_id' => $orgId,
                    'roles_count' => $userRoles->count(),
                    'roles' => $userRoles->pluck('slug')->toArray()
                ]);
                
                // Проверяем наличие роли Owner или Admin
                $hasOwnerRole = $userRoles->contains('slug', Role::ROLE_OWNER);
                $hasAdminRole = $userRoles->contains('slug', Role::ROLE_ADMIN);
                
                Log::info('[Gate:access-landing] Результаты проверки ролей через SQL', [
                    'user_id' => $user->id,
                    'organization_id' => $orgId,
                    'has_owner_role' => $hasOwnerRole,
                    'has_admin_role' => $hasAdminRole
                ]);
                
                if ($hasOwnerRole || $hasAdminRole) {
                    Log::info('[Gate:access-landing] Доступ РАЗРЕШЕН через SQL', [
                        'user_id' => $user->id,
                        'organization_id' => $orgId
                    ]);
                    return true;
                } else {
                    Log::warning('[Gate:access-landing] Доступ ЗАПРЕЩЕН через SQL', [
                        'user_id' => $user->id,
                        'organization_id' => $orgId
                    ]);
                    return false;
                }
            } catch (\Throwable $e) {
                Log::error('[Gate:access-landing] Ошибка при проверке ролей через SQL. Сообщение: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'organization_id' => $orgId, // Используем $orgId, который был определен до try
                    // Убираем полный стектрейс из этого лога, чтобы уменьшить вероятность сбоя самого логирования
                    // 'trace' => $e->getTraceAsString()
                ]);
                
                // В случае ошибки делаем запасной вариант - проверяем через модель
                $hasOwnerRole = $user->hasRole(Role::ROLE_OWNER, $orgId);
                $hasAdminRole = $user->hasRole(Role::ROLE_ADMIN, $orgId);
                
                Log::info('[Gate:access-landing] Результат запасной проверки через модель', [
                    'user_id' => $user->id,
                    'organization_id' => $orgId,
                    'has_owner_role' => $hasOwnerRole,
                    'has_admin_role' => $hasAdminRole
                ]);
                
                return $hasOwnerRole || $hasAdminRole;
            }
        });

        /**
         * Gate для доступа к Админ-панели.
         * Требует роль System Admin ИЛИ одну из ролей (Owner, Admin, Web Admin, Accountant)
         * в текущей организации.
         */
        Gate::define('access-admin-panel', function (User $user, ?int $organizationId = null): bool {
            // Логирование для отладки, какой $organizationId приходит в Gate
            Log::debug('[Gate:access-admin-panel] Checking access', [
                'user_id' => $user->id,
                'passed_organization_id' => $organizationId,
                'user_current_organization_id' => $user->current_organization_id,
            ]);

            // Просто используем новый метод из модели User.
            // Метод isAdminPanelUser сам обработает случай system_admin и контекст организации.
            $canAccess = $user->isAdminPanelUser($organizationId);

            Log::info('[Gate:access-admin-panel] Access result', [
                'user_id' => $user->id,
                'can_access' => $canAccess
            ]);
            return $canAccess;
        });

        /**
         * Gate для доступа к Мобильному приложению.
         * Требует роль Foreman в текущей организации.
         */
        Gate::define('access-mobile-app', function (User $user, ?int $organizationId = null): bool {
            // Лог на самом входе в Gate
            Log::info('[Gate:access-mobile-app] Entered Gate closure.', ['user_id' => $user->id, 'passed_org_id' => $organizationId]);
            
            try {
                $orgId = $organizationId ?? $user->current_organization_id;
                Log::info('[Gate:access-mobile-app] Checking access.', [
                    'user_id' => $user->id, 
                    'passed_org_id' => $organizationId, // ID из контроллера
                    'user_current_org_id' => $user->current_organization_id, // ID из объекта user
                    'effective_org_id' => $orgId // ID, который будет использоваться для проверки
                ]);
                
                if (!$orgId) {
                    Log::warning('[Gate:access-mobile-app] Access denied. Effective organization ID is missing.', ['user_id' => $user->id]);
                    return false;
                }
                
                // Проверяем роль Foreman
                Log::debug('[Gate:access-mobile-app] Checking role.', ['user_id' => $user->id, 'org_id' => $orgId, 'role' => Role::ROLE_FOREMAN]);
                $hasRole = $user->hasRole(Role::ROLE_FOREMAN, $orgId);
                Log::info('[Gate:access-mobile-app] Role check result.', [
                    'user_id' => $user->id, 
                    'org_id' => $orgId, 
                    'role_checked' => Role::ROLE_FOREMAN, 
                    'has_role' => $hasRole
                ]);
                return $hasRole;
            } catch (\Throwable $e) {
                // Логируем любую ошибку внутри Gate
                Log::error('[Gate:access-mobile-app] Exception caught within Gate closure.', [
                    'user_id' => $user->id, 
                    'passed_org_id' => $organizationId,
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString() // Полный стек для детального анализа
                ]);
                return false; // В случае ошибки считаем, что доступа нет
            }
        });

        /**
         * Gate для управления прорабами (создание, редактирование, удаление).
         * Требует роль System Admin ИЛИ Owner/Admin в текущей организации.
         */
        Gate::define('manage-foremen', function(User $user, ?int $organizationId = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false; // Нет контекста организации
            }
            // Проверяем роли Owner или Admin в указанной организации
            $canManage = $user->hasRole(Role::ROLE_OWNER, $orgId) || $user->hasRole(Role::ROLE_ADMIN, $orgId);
            Log::debug('[Gate:manage-foremen] Check result.', [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'can_manage' => $canManage
            ]);
            return $canManage;
        });

        /**
         * Gate для управления назначениями прорабов на проекты.
         * Требует роль System Admin ИЛИ Owner/Admin в текущей организации.
         */
        Gate::define('manage-project-assignments', function(User $user, ?int $organizationId = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false; // Нет контекста организации
            }
            // Используем ту же логику, что и для manage-foremen
            return $user->hasRole(Role::ROLE_OWNER, $orgId) || $user->hasRole(Role::ROLE_ADMIN, $orgId);
            // Можно добавить лог, если нужно
            // Log::debug('[Gate:manage-project-assignments] Check result.', [/*...*/]);
        });

        /**
         * Gate для управления справочниками (материалы, виды работ, поставщики).
         * Требует роль System Admin ИЛИ Owner/Admin в текущей организации.
         */
        Gate::define('manage-catalogs', function(User $user, ?int $organizationId = null): bool {
            try {
                if ($user->isSystemAdmin()) {
                    Log::info('[Gate:manage-catalogs] Access GRANTED for System Admin.', ['user_id' => $user->id]);
                    return true;
                }
                $orgId = $organizationId ?? $user->current_organization_id;
                if (!$orgId) {
                    Log::warning('[Gate:manage-catalogs] Access DENIED due to missing organizationId.', ['user_id' => $user->id]);
                    return false; 
                }
                
                $allowedRoles = [
                    Role::ROLE_OWNER,
                    Role::ROLE_ADMIN,
                    Role::ROLE_ACCOUNTANT,
                    Role::ROLE_WEB_ADMIN,
                    'admin',        // системная роль admin
                    'super_admin',  // системная роль super_admin
                ];
                Log::debug('[Gate:manage-catalogs] Checking roles for user.', ['user_id' => $user->id, 'org_id' => $orgId, 'allowed_roles' => $allowedRoles]);

                foreach ($allowedRoles as $roleSlug) {
                    if ($user->hasRole($roleSlug, $orgId)) {
                        Log::info('[Gate:manage-catalogs] Access GRANTED for user via role.', ['user_id' => $user->id, 'role_slug' => $roleSlug, 'org_id' => $orgId]);
                        return true;
                    }
                }
                Log::warning('[Gate:manage-catalogs] Access DENIED for user. No matching role found.', ['user_id' => $user->id, 'org_id' => $orgId, 'checked_roles' => $allowedRoles]);
                return false;
            } catch (\Throwable $e) {
                Log::error('[Gate:manage-catalogs] Exception caught inside Gate definition!', [
                    'user_id' => $user->id,
                    'passed_organization_id' => $organizationId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return false; // В случае ошибки не даем доступ
            }
        });

        /**
         * Gate для просмотра отчетов в админке.
         * Требует роль System Admin ИЛИ одну из ролей (Owner, Admin, Web Admin, Accountant)
         * в текущей организации.
         */
        Gate::define('view-reports', function(User $user, ?int $organizationId = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false;
            }
            $allowedRoles = [
                Role::ROLE_OWNER,
                Role::ROLE_ADMIN,
                Role::ROLE_WEB_ADMIN,
                Role::ROLE_ACCOUNTANT,
            ];
            foreach ($allowedRoles as $roleSlug) {
                if ($user->hasRole($roleSlug, $orgId)) {
                    return true;
                }
            }
            return false;
        });

        /**
         * Gate для просмотра логов операций прорабов в админке.
         * Требует роль System Admin ИЛИ одну из ролей (Owner, Admin, Web Admin)
         * в текущей организации. Бухгалтеру (Accountant) не даем доступ к сырым логам.
         */
        Gate::define('view-operation-logs', function(User $user, ?int $organizationId = null): bool {
            try {
                if ($user->isSystemAdmin()) { // Системный администратор всегда имеет доступ
                    Log::info('[Gate:view-operation-logs] Access GRANTED for System Admin.', ['user_id' => $user->id]);
                    return true;
                }
                $orgId = $organizationId ?? $user->current_organization_id;
                if (!$orgId) {
                    Log::warning('[Gate:view-operation-logs] Access denied due to missing organizationId.', ['user_id' => $user->id]);
                    return false;
                }
                // Обновленный список разрешенных ролей
                $allowedRoles = [
                    Role::ROLE_OWNER,
                    Role::ROLE_ADMIN,
                    Role::ROLE_WEB_ADMIN,
                    Role::ROLE_ACCOUNTANT, // Добавляем бухгалтера
                ];
                Log::debug('[Gate:view-operation-logs] Checking roles for user.', ['user_id' => $user->id, 'org_id' => $orgId, 'allowed_roles' => $allowedRoles]);
                foreach ($allowedRoles as $roleSlug) {
                    // Log::debug('[Gate:view-operation-logs] Checking role.', ['user_id' => $user->id, 'role_slug' => $roleSlug]); // Можно раскомментировать для более детальной отладки каждой роли
                    if ($user->hasRole($roleSlug, $orgId)) {
                        Log::info('[Gate:view-operation-logs] Access GRANTED for user via role.', ['user_id' => $user->id, 'role_slug' => $roleSlug, 'org_id' => $orgId]);
                        return true;
                    }
                }
                Log::warning('[Gate:view-operation-logs] Access DENIED for user. No matching role found.', ['user_id' => $user->id, 'org_id' => $orgId, 'checked_roles' => $allowedRoles]);
                return false;
            } catch (\Throwable $e) {
                Log::error('[Gate:view-operation-logs] Exception caught inside Gate definition!', [
                    'user_id' => $user->id,
                    'passed_organization_id' => $organizationId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return false; // В случае ошибки не даем доступ
            }
        });

        /**
         * Gate для управления заявками с объекта (SiteRequest).
         * Разрешено автору заявки или пользователю с ролью Admin (organization_admin) или System Admin.
         * Второй аргумент $siteRequest может быть null, если Gate используется для проверки возможности создания новой заявки (в этом случае проверка по автору невозможна).
         */
        Gate::define('manage_site_requests', function (User $user, ?SiteRequest $siteRequest = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }

            // Если это проверка для конкретной заявки, и пользователь ее автор
            if ($siteRequest && $siteRequest->user_id === $user->id) {
                return true;
            }

            // Пользователь с ролью Admin в организации заявки (или текущей организации пользователя, если заявка еще не создана)
            $organizationId = $siteRequest?->organization_id ?? $user->current_organization_id;
            if (!$organizationId) {
                 Log::warning('[Gate:manage_site_requests] Access DENIED due to missing organizationId for user.', ['user_id' => $user->id]);
                return false;
            }
            
            if ($user->hasRole(Role::ROLE_ADMIN, $organizationId)) {
                return true;
            }

            Log::info('[Gate:manage_site_requests] Access DENIED for user.', [
                'user_id' => $user->id,
                'site_request_id' => $siteRequest?->id,
                'site_request_user_id' => $siteRequest?->user_id,
                'organization_id_of_check' => $organizationId
            ]);
            return false;
        });

        /**
         * Gate для управления вложениями в заявках с объекта (SiteRequest attachments).
         * Логика аналогична 'manage_site_requests'.
         */
        Gate::define('manage_site_request_attachments', function (User $user, ?SiteRequest $siteRequest = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }

            if ($siteRequest && $siteRequest->user_id === $user->id) {
                return true;
            }

            $organizationId = $siteRequest?->organization_id ?? $user->current_organization_id;
            if (!$organizationId) {
                Log::warning('[Gate:manage_site_request_attachments] Access DENIED due to missing organizationId for user.', ['user_id' => $user->id]);
                return false;
            }

            if ($user->hasRole(Role::ROLE_ADMIN, $organizationId)) {
                return true;
            }
            
            Log::info('[Gate:manage_site_request_attachments] Access DENIED for user.', [
                'user_id' => $user->id,
                'site_request_id' => $siteRequest?->id,
                'site_request_user_id' => $siteRequest?->user_id,
                'organization_id_of_check' => $organizationId
            ]);
            return false;
        });

        // TODO: Добавить другие Gates по мере необходимости
    }
}
