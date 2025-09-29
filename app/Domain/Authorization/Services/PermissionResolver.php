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
        $startTime = microtime(true);
        
        $this->logging->security('permission.resolve.start', [
            'user_id' => $assignment->user_id,
            'role_slug' => $assignment->role_slug,
            'role_type' => $assignment->role_type,
            'permission' => $permission,
            'context' => $context
        ]);

        // Сначала проверяем системные права
        $hasSystemPerm = $this->hasSystemPermission($assignment, $permission);
        
        if ($hasSystemPerm) {
            $this->logging->security('permission.granted.system', [
                'user_id' => $assignment->user_id,
                'role_slug' => $assignment->role_slug,
                'permission' => $permission,
                'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            return true;
        }

        // Затем модульные права (если есть контекст организации)
        $hasModulePerm = $this->hasModulePermission($assignment, $permission, $context);
        
        if ($hasModulePerm) {
            $this->logging->security('permission.granted.module', [
                'user_id' => $assignment->user_id,
                'role_slug' => $assignment->role_slug,
                'permission' => $permission,
                'context' => $context,
                'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            return true;
        }

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

        return false;
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
        // Определяем организацию из контекста
        $organizationId = $this->extractOrganizationId($assignment, $context);
        
        if (!$organizationId) {
            return false;
        }

        // Разбираем permission на модуль и действие (например: projects.view -> projects, view)
        $parts = explode('.', $permission, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$module, $action] = $parts;
        
        // Проверяем, активирован ли модуль для организации
        if (!$this->moduleChecker->isModuleActive($module, $organizationId)) {
            return false;
        }

        // Проверяем модульные права роли
        $modulePermissions = $this->getModulePermissions($assignment);
        
        return $this->checkModulePermission($modulePermissions, $module, $action);
    }

    /**
     * Получить все системные права роли
     */
    public function getSystemPermissions(UserRoleAssignment $assignment): array
    {
        if ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM) {
            $permissions = $this->roleScanner->getSystemPermissions($assignment->role_slug);
            return $permissions;
        } else {
            $customRole = $this->getCustomRole($assignment->role_slug);
            $permissions = $customRole ? $customRole->system_permissions : [];
            return $permissions;
        }
    }

    /**
     * Получить все модульные права роли
     */
    public function getModulePermissions(UserRoleAssignment $assignment): array
    {
        if ($assignment->role_type === UserRoleAssignment::TYPE_SYSTEM) {
            return $this->roleScanner->getModulePermissions($assignment->role_slug);
        } else {
            $customRole = $this->getCustomRole($assignment->role_slug);
            return $customRole ? $customRole->module_permissions : [];
        }
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

        // Затем из контекста назначения
        $authContext = $assignment->context;
        
        if ($authContext->type === 'organization') {
            return $authContext->resource_id;
        }

        if ($authContext->type === 'project') {
            // Нужно получить организацию проекта через родительский контекст
            $parentContext = $authContext->parentContext;
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
