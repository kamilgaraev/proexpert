<?php

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для резолвинга прав (системных и модульных)
 */
class PermissionResolver
{
    protected RoleScanner $roleScanner;
    protected ModulePermissionChecker $moduleChecker;

    public function __construct(
        RoleScanner $roleScanner,
        ModulePermissionChecker $moduleChecker
    ) {
        $this->roleScanner = $roleScanner;
        $this->moduleChecker = $moduleChecker;
    }

    /**
     * Проверить, есть ли у назначения роли указанное право
     */
    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: Checking permission', [
            'role_slug' => $assignment->role_slug,
            'role_type' => $assignment->role_type,
            'permission' => $permission,
            'context' => $context
        ]);

        // Сначала проверяем системные права
        $hasSystemPerm = $this->hasSystemPermission($assignment, $permission);
        \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: System permission check', [
            'role_slug' => $assignment->role_slug,
            'permission' => $permission,
            'has_system_permission' => $hasSystemPerm
        ]);
        
        if ($hasSystemPerm) {
            return true;
        }

        // Затем модульные права (если есть контекст организации)
        $hasModulePerm = $this->hasModulePermission($assignment, $permission, $context);
        \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: Module permission check', [
            'role_slug' => $assignment->role_slug,
            'permission' => $permission,
            'has_module_permission' => $hasModulePerm
        ]);
        
        if ($hasModulePerm) {
            return true;
        }

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
            \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: System role permissions from RoleScanner', [
                'role_slug' => $assignment->role_slug,
                'permissions_count' => count($permissions),
                'permissions' => $permissions
            ]);
            return $permissions;
        } else {
            $customRole = $this->getCustomRole($assignment->role_slug);
            $permissions = $customRole ? $customRole->system_permissions : [];
            \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: Custom role permissions', [
                'role_slug' => $assignment->role_slug,
                'custom_role_found' => $customRole !== null,
                'permissions_count' => count($permissions),
                'permissions' => $permissions
            ]);
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
     * @param string $pattern Wildcard шаблон (например: admin.*)
     * @return bool
     */
    protected function matchesWildcard(string $permission, string $pattern): bool
    {
        // Если нет wildcard, то точное сравнение
        if (strpos($pattern, '*') === false) {
            return $permission === $pattern;
        }
        
        // ИСПРАВЛЕНО: сначала заменяем *, потом экранируем остальное
        $regexPattern = str_replace('*', '.*', $pattern);  // admin.* → admin..*
        $regexPattern = '/^' . str_replace('.', '\.', $regexPattern) . '$/';  // admin..* → /^admin\..*$/
        
        $result = preg_match($regexPattern, $permission) === 1;
        
        // DEBUG логирование
        \Illuminate\Support\Facades\Log::info('[PermissionResolver] DEBUG: Wildcard match', [
            'permission' => $permission,
            'pattern' => $pattern,
            'regex' => $regexPattern,
            'result' => $result
        ]);
        
        return $result;
    }
}
