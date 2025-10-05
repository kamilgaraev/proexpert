<?php

namespace App\Domain\Authorization\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\RoleUpdater;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Policies\CustomRolePolicy;
use App\Services\Logging\LoggingService;

/**
 * Service Provider для новой системы авторизации
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрируем сервисы как синглтоны
        $this->app->singleton(RoleScanner::class, function ($app) {
            return new RoleScanner();
        });

        $this->app->singleton(ModulePermissionChecker::class, function ($app) {
            return new ModulePermissionChecker(
                $app->make(\App\Modules\Core\AccessController::class)
            );
        });

        $this->app->singleton(PermissionResolver::class, function ($app) {
            return new PermissionResolver(
                $app->make(RoleScanner::class),
                $app->make(ModulePermissionChecker::class),
                $app->make(LoggingService::class)
            );
        });

        $this->app->singleton(AuthorizationService::class, function ($app) {
            return new AuthorizationService(
                $app->make(RoleScanner::class),
                $app->make(PermissionResolver::class),
                $app->make(LoggingService::class)
            );
        });

        $this->app->singleton(CustomRoleService::class, function ($app) {
            return new CustomRoleService(
                $app->make(RoleScanner::class),
                $app->make(ModulePermissionChecker::class),
                $app->make(AuthorizationService::class)
            );
        });

        $this->app->singleton(RoleUpdater::class, function ($app) {
            return new RoleUpdater(
                $app->make(RoleScanner::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerGates();
        $this->registerPolicies();
        $this->registerEventListeners();
        
        // Регистрируем команды, если они есть
        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }
    }

    /**
     * Регистрируем Gates для новой системы авторизации
     */
    protected function registerGates(): void
    {
        $authService = $this->app->make(AuthorizationService::class);

        // Основной Gate для проверки прав
        Gate::define('authorize', function ($user, $permission, $context = null) use ($authService) {
            return $authService->can($user, $permission, $context);
        });

        // Gate для проверки ролей
        Gate::define('has-role', function ($user, $roleSlug, $contextId = null) use ($authService) {
            return $authService->hasRole($user, $roleSlug, $contextId);
        });

        // Gate для доступа к интерфейсам
        Gate::define('access-interface', function ($user, $interface, $context = null) use ($authService) {
            return $authService->canAccessInterface($user, $interface, $context);
        });

        // Gates для управления пользователями
        Gate::define('manage-user', function ($user, $targetUser, $context = null) use ($authService) {
            return $authService->canManageUser($user, $targetUser, $context);
        });

        // Gates для кастомных ролей
        Gate::define('create-custom-role', function ($user, $organizationId) {
            return $user->can('roles.create_custom', ['organization_id' => $organizationId]);
        });

        Gate::define('manage-custom-role', function ($user, $organizationId) {
            return $user->can('roles.manage_custom', ['organization_id' => $organizationId]);
        });
    }

    /**
     * Регистрируем политики для моделей
     */
    protected function registerPolicies(): void
    {
        Gate::policy(OrganizationCustomRole::class, CustomRolePolicy::class);
    }

    /**
     * Регистрируем слушатели событий
     */
    protected function registerEventListeners(): void
    {
        // NOTE: Listeners отключены - права модулей определяются статически в файлах ролей.
        // Система динамически проверяет активность модуля в организации при проверке прав.
        // Изменение файлов конфигурации на проде недопустимо по соображениям безопасности.
    }

    /**
     * Регистрируем консольные команды
     */
    protected function registerCommands(): void
    {
        // Можно добавить команды для управления ролями
        // $this->commands([
        //     Commands\ScanRolesCommand::class,
        //     Commands\ClearRoleCacheCommand::class,
        // ]);
    }
}
