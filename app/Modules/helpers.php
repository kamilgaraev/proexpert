<?php

use App\Modules\Services\ModulePermissionService;
use App\Modules\Core\ModuleManager;
use App\Modules\Core\AccessController;

if (!function_exists('hasModuleAccess')) {
    /**
     * Проверить доступ пользователя к модулю
     */
    function hasModuleAccess(string $moduleSlug, ?\App\Models\User $user = null): bool
    {
        $user = $user ?: auth()->user();
        
        if (!$user) {
            return false;
        }

        $permissionService = app(ModulePermissionService::class);
        return $permissionService->userHasModuleAccess($user, $moduleSlug);
    }
}

if (!function_exists('hasModulePermission')) {
    /**
     * Проверить наличие у пользователя конкретного разрешения модуля
     */
    function hasModulePermission(string $permission, ?\App\Models\User $user = null): bool
    {
        $user = $user ?: auth()->user();
        
        if (!$user) {
            return false;
        }

        $permissionService = app(ModulePermissionService::class);
        return $permissionService->userHasPermission($user, $permission);
    }
}

if (!function_exists('getActiveModules')) {
    /**
     * Получить активные модули пользователя
     */
    function getActiveModules(?\App\Models\User $user = null): \Illuminate\Support\Collection
    {
        $user = $user ?: auth()->user();
        
        if (!$user) {
            return collect();
        }

        $permissionService = app(ModulePermissionService::class);
        return $permissionService->getUserActiveModules($user);
    }
}

if (!function_exists('getUserPermissions')) {
    /**
     * Получить все доступные разрешения пользователя
     */
    function getUserPermissions(?\App\Models\User $user = null): array
    {
        $user = $user ?: auth()->user();
        
        if (!$user) {
            return [];
        }

        $permissionService = app(ModulePermissionService::class);
        return $permissionService->getUserAvailablePermissions($user);
    }
}

if (!function_exists('moduleRoute')) {
    /**
     * Генерировать роут для модульной системы
     */
    function moduleRoute(string $routeName, array $parameters = []): string
    {
        return route("api.v1.landing.modules.{$routeName}", $parameters);
    }
}

if (!function_exists('canActivateModule')) {
    /**
     * Проверить возможность активации модуля
     */
    function canActivateModule(string $moduleSlug, ?int $organizationId = null): bool
    {
        $user = auth()->user();
        $organizationId = $organizationId ?: ($user ? $user->current_organization_id : null);
        
        if (!$organizationId) {
            return false;
        }

        $accessController = app(AccessController::class);
        
        // Проверяем что модуль не активирован
        if ($accessController->hasModuleAccess($organizationId, $moduleSlug)) {
            return false;
        }

        $module = \App\Models\Module::where('slug', $moduleSlug)->where('is_active', true)->first();
        
        if (!$module) {
            return false;
        }

        // Проверяем зависимости и конфликты
        $missingDependencies = $accessController->checkDependencies($organizationId, $module);
        $conflicts = $accessController->checkConflicts($organizationId, $module);
        
        if (!empty($missingDependencies) || !empty($conflicts)) {
            return false;
        }

        // Проверяем баланс
        $organization = \App\Models\Organization::find($organizationId);
        if (!$organization) {
            return false;
        }

        $billingEngine = app(\App\Modules\Core\BillingEngine::class);
        return $billingEngine->canAfford($organization, $module);
    }
}

if (!function_exists('getModulePrice')) {
    /**
     * Получить цену модуля
     */
    function getModulePrice(string $moduleSlug): float
    {
        $module = \App\Models\Module::where('slug', $moduleSlug)->first();
        
        return $module ? $module->getPrice() : 0;
    }
}

if (!function_exists('isModuleFree')) {
    /**
     * Проверить является ли модуль бесплатным
     */
    function isModuleFree(string $moduleSlug): bool
    {
        $module = \App\Models\Module::where('slug', $moduleSlug)->first();
        
        return $module ? $module->isFree() : false;
    }
}

if (!function_exists('refreshModuleRegistry')) {
    /**
     * Обновить реестр модулей (пересканировать конфигурации)
     */
    function refreshModuleRegistry(): void
    {
        $registry = app(\App\Modules\Core\ModuleRegistry::class);
        $registry->refreshRegistry();
    }
}
