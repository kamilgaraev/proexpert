<?php

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для резолвинга прав (системных и модульных)
 */
class PermissionResolver
{
    protected RoleScanner $roleScanner;
    protected ModulePermissionChecker $moduleChecker;
    protected LoggingService $logging;

    public function __construct(
        RoleScanner $roleScanner,
        ModulePermissionChecker $moduleChecker,
        LoggingService $logging
    ) {
        $this->roleScanner = $roleScanner;
        $this->moduleChecker = $moduleChecker;
        $this->logging = $logging;
    }

    /**
     * Проверить, есть ли у назначения роли указанное право
     */
    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        static $depthCounter = 0;
        $maxDepth = 50;
        
        if ($depthCounter >= $maxDepth) {
            $this->logging->security('permission.max_depth_exceeded', [
                'user_id' => $assignment->user_id,
                'permission' => $permission,
                'max_depth' => $maxDepth
            ], 'error');
            return false;
        }
        
        $depthCounter++;
        
        try {
            $startTime = microtime(true);
            
            $cacheKey = $this->getVersionedCacheKey($assignment->user_id, $assignment->role_slug, $permission, $context);
            $cachedResult = Cache::get($cacheKey);
            
            if ($cachedResult !== null) {
                return $cachedResult;
            }
            
            $timeout = 5.0;
            if ((microtime(true) - $startTime) > $timeout) {
                $this->logging->security('permission.resolve.timeout', [
                    'user_id' => $assignment->user_id,
                    'permission' => $permission,
                    'timeout_seconds' => $timeout
                ], 'error');
                return false;
            }
            
            $userAgent = request()->userAgent() ?? '';
            if (!str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('permission.resolve.start', [
                    'user_id' => $assignment->user_id,
                    'role_slug' => $assignment->role_slug,
                    'role_type' => $assignment->role_type,
                    'permission' => $permission,
                    'context' => $context,
                    'depth' => $depthCounter
                ]);
            }

            $hasSystemPerm = $this->hasSystemPermission($assignment, $permission);
            
            if ($hasSystemPerm) {
                Cache::put($cacheKey, true, 300);
                
                if (!str_contains($userAgent, 'Prometheus')) {
                    $this->logging->security('permission.granted.system', [
                        'user_id' => $assignment->user_id,
                        'role_slug' => $assignment->role_slug,
                        'permission' => $permission,
                        'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                    ]);
                }
                return true;
            }

            $hasModulePerm = $this->hasModulePermission($assignment, $permission, $context);
            
            if ($hasModulePerm) {
                Cache::put($cacheKey, true, 300);
                
                if (!str_contains($userAgent, 'Prometheus')) {
                    $this->logging->security('permission.granted.module', [
                        'user_id' => $assignment->user_id,
                        'role_slug' => $assignment->role_slug,
                        'permission' => $permission,
                        'context' => $context,
                        'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                    ]);
                }
                return true;
            }

            Cache::put($cacheKey, false, 300);
            
            if (!str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('permission.denied.complete', [
                    'user_id' => $assignment->user_id,
                    'role_slug' => $assignment->role_slug,
                    'role_type' => $assignment->role_type,
                    'permission' => $permission,
                    'context' => $context,
                    'checked_system' => true,
                    'checked_modules' => true,
                    'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ], 'info');
            }

            return false;
        } finally {
            $depthCounter--;
        }
    }

    /**
     * Проверить системное право
     */
    public function hasSystemPermission(UserRoleAssignment $assignment, string $permission): bool
    {
        $systemPermissions = $this->getSystemPermissions($assignment);
        
        // Проверяем точное совпадение
        if (in_array($permission, $systemPermissions)) {
            return true;
        }

        // Проверяем wildcard права
        if (in_array('*', $systemPermissions)) {
            return true;
        }

        // Проверяем права с точками (например: users.* для users.view)
        foreach ($systemPermissions as $rolePermission) {
            if ($this->matchesWildcard($permission, $rolePermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить модульное право
     */
    public function hasModulePermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        $organizationId = $this->extractOrganizationId($assignment, $context);
        
        if (!$organizationId) {
            return false;
        }

        $parts = explode('.', $permission, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$module, $action] = $parts;
        
        $cacheKey = "module_active_{$module}_{$organizationId}";
        $isActive = Cache::remember($cacheKey, 300, function () use ($module, $organizationId) {
            return $this->moduleChecker->isModuleActive($module, $organizationId);
        });
        
        if (!$isActive) {
            return false;
        }

        $modulePermissions = $this->getModulePermissions($assignment);
        
        return $this->checkModulePermission($modulePermissions, $module, $action);
    }

    /**
     * Получить все системные права роли
     */
    public function getSystemPermissions(UserRoleAssignment $assignment): array
    {
        $cacheKey = "system_perms_{$assignment->role_type}_{$assignment->role_slug}";
        
        return Cache::remember($cacheKey, 600, function () use ($assignment) {
            if ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM) {
                return $this->roleScanner->getSystemPermissions($assignment->role_slug);
            } else {
                $customRole = $this->getCustomRole($assignment->role_slug);
                return $customRole ? $customRole->system_permissions : [];
            }
        });
    }

    /**
     * Получить все модульные права роли
     */
    public function getModulePermissions(UserRoleAssignment $assignment): array
    {
        $cacheKey = "module_perms_{$assignment->role_type}_{$assignment->role_slug}";
        
        return Cache::remember($cacheKey, 600, function () use ($assignment) {
            if ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM) {
                return $this->roleScanner->getModulePermissions($assignment->role_slug);
            } else {
                $customRole = $this->getCustomRole($assignment->role_slug);
                return $customRole ? $customRole->module_permissions : [];
            }
        });
    }

    /**
     * Получить все права системной роли
     */
    public function getSystemRolePermissions(string $roleSlug): array
    {
        $systemPermissions = $this->roleScanner->getSystemPermissions($roleSlug);
        $modulePermissions = $this->roleScanner->getModulePermissions($roleSlug);
        
        $allPermissions = $systemPermissions;
        
        // Преобразуем модульные права в полные права
        foreach ($modulePermissions as $module => $permissions) {
            foreach ($permissions as $permission) {
                if ($permission === '*') {
                    $allPermissions[] = "$module.*";
                } else {
                    $allPermissions[] = "$module.$permission";
                }
            }
        }
        
        return array_unique($allPermissions);
    }

    /**
     * Получить все права кастомной роли
     */
    public function getCustomRolePermissions(string $roleSlug): array
    {
        $customRole = $this->getCustomRole($roleSlug);
        
        if (!$customRole) {
            return [];
        }
        
        $allPermissions = $customRole->system_permissions ?? [];
        
        // Преобразуем модульные права в полные права
        foreach ($customRole->module_permissions ?? [] as $module => $permissions) {
            foreach ($permissions as $permission) {
                if ($permission === '*') {
                    $allPermissions[] = "$module.*";
                } else {
                    $allPermissions[] = "$module.$permission";
                }
            }
        }
        
        return array_unique($allPermissions);
    }


    /**
     * Проверить модульное право
     */
    protected function checkModulePermission(array $modulePermissions, string $module, string $action): bool
    {
        if (!isset($modulePermissions[$module])) {
            return false;
        }

        $permissions = $modulePermissions[$module];
        
        // Проверяем точное совпадение
        if (in_array($action, $permissions)) {
            return true;
        }

        // Проверяем wildcard
        if (in_array('*', $permissions)) {
            return true;
        }

        // Проверяем wildcard с префиксом (например: create_* для create_project)
        foreach ($permissions as $permission) {
            if ($this->matchesWildcard($action, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Извлечь ID организации из назначения или контекста
     */
    protected function extractOrganizationId(UserRoleAssignment $assignment, ?array $context = null): ?int
    {
        // Сначала пробуем из контекста
        if ($context && isset($context['organization_id'])) {
            return $context['organization_id'];
        }

        // Затем из контекста назначения (с eager loading)
        $authContext = $assignment->relationLoaded('context') 
            ? $assignment->context 
            : $assignment->load('context.parentContext')->context;
        
        if ($authContext && $authContext->type === 'organization') {
            return $authContext->resource_id;
        }

        if ($authContext && $authContext->type === 'project') {
            // Нужно получить организацию проекта через родительский контекст
            $parentContext = $authContext->relationLoaded('parentContext') 
                ? $authContext->parentContext 
                : $authContext->load('parentContext')->parentContext;
            if ($parentContext && $parentContext->type === 'organization') {
                return $parentContext->resource_id;
            }
        }

        return null;
    }

    /**
     * Получить кастомную роль с кешированием
     */
    protected function getCustomRole(string $roleSlug): ?OrganizationCustomRole
    {
        return Cache::remember("custom_role_$roleSlug", 300, function () use ($roleSlug) {
            return OrganizationCustomRole::where('slug', $roleSlug)->first();
        });
    }

    /**
     * Очистить кеш разрешений для пользователя
     */
    public function clearUserPermissionCache(int $userId): void
    {
        // Простое решение - используем тег кеша или версионирование
        Cache::forget("user_permission_version_{$userId}");
        
        // Для более сложной очистки можно использовать версионирование кеша
        $currentVersion = Cache::get("user_permission_version_{$userId}", 0);
        Cache::put("user_permission_version_{$userId}", $currentVersion + 1, 3600);
    }

    /**
     * Очистить весь кеш разрешений (для emergency случаев)
     */
    public function clearAllPermissionCache(): void
    {
        $currentVersion = Cache::get("permission_global_version", 0);
        Cache::put("permission_global_version", $currentVersion + 1, 3600);
    }

    /**
     * Получить ключ кеша с версионированием
     */
    protected function getVersionedCacheKey(int $userId, string $roleSlug, string $permission, ?array $context = null): string
    {
        $userVersion = Cache::get("user_permission_version_{$userId}", 0);
        $globalVersion = Cache::get("permission_global_version", 0);
        
        $baseKey = "permission_{$userId}_{$roleSlug}_{$permission}_" . md5(json_encode($context));
        return "{$baseKey}_v{$userVersion}_{$globalVersion}";
    }

    /**
     * Проверить соответствие wildcard шаблону
     * 
     * @param string $permission Проверяемое право (например: admin.access)
     * @param string $pattern Wildcard шаблон (например: admin.*, *.view, admin.*.edit)
     * @return bool
     */
    protected function matchesWildcard(string $permission, string $pattern): bool
    {
        // Если нет wildcard, то точное сравнение
        if (strpos($pattern, '*') === false) {
            return $permission === $pattern;
        }
        
        // Полный wildcard
        if ($pattern === '*') {
            return true;
        }
        
        // ПРАВИЛЬНЫЙ АЛГОРИТМ: сначала * → плейсхолдер, потом экранируем, потом плейсхолдер → .*
        // 1. Заменяем * на уникальный плейсхолдер
        $placeholder = '___WILDCARD_PLACEHOLDER___';
        $withPlaceholder = str_replace('*', $placeholder, $pattern);
        
        // 2. Экранируем все regex спецсимволы 
        $escaped = preg_quote($withPlaceholder, '/');
        
        // 3. Заменяем плейсхолдер на .*
        $regexPattern = '/^' . str_replace($placeholder, '.*', $escaped) . '$/';
        
        $result = preg_match($regexPattern, $permission) === 1;
        
        return $result;
    }
}
