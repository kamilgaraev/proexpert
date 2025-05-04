<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Log;

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

        /**
         * Gate для доступа к Лендинг/ЛК API.
         * Требует роль Owner или Admin в текущей организации.
         */
        Gate::define('access-landing', function (User $user, ?int $organizationId = null): bool {
            // Используем ID организации из контекста пользователя, если не передан явно
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false; // Невозможно определить контекст организации
            }
            // Проверяем роли Owner или Admin
            return $user->hasRole(Role::ROLE_OWNER, $orgId) || $user->hasRole(Role::ROLE_ADMIN, $orgId);
        });

        /**
         * Gate для доступа к Админ-панели.
         * Требует роль System Admin ИЛИ одну из ролей (Owner, Admin, Web Admin, Accountant)
         * в текущей организации.
         */
        Gate::define('access-admin-panel', function (User $user, ?int $organizationId = null): bool {
            if ($user->isSystemAdmin()) {
                return true;
            }
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false;
            }
            foreach (User::ADMIN_PANEL_ACCESS_ROLES as $roleSlug) {
                if ($roleSlug === Role::ROLE_SYSTEM_ADMIN) continue; // Системного админа уже проверили
                if ($user->hasRole($roleSlug, $orgId)) {
                    return true;
                }
            }
            return false;
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
            if ($user->isSystemAdmin()) {
                return true;
            }
            $orgId = $organizationId ?? $user->current_organization_id;
            if (!$orgId) {
                return false; // Нет контекста организации
            }
            // Используем ту же логику, что и для manage-foremen
            return $user->hasRole(Role::ROLE_OWNER, $orgId) || $user->hasRole(Role::ROLE_ADMIN, $orgId);
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
                // Role::ROLE_ACCOUNTANT, // Исключаем бухгалтера
            ];
            foreach ($allowedRoles as $roleSlug) {
                if ($user->hasRole($roleSlug, $orgId)) {
                    return true;
                }
            }
            return false;
        });

        // TODO: Добавить другие Gates по мере необходимости
    }
}
