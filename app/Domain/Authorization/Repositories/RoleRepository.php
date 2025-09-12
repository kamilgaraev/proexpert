<?php

namespace App\Domain\Authorization\Repositories;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Репозиторий для работы с ролями (JSON + кастомные)
 */
class RoleRepository
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    /**
     * Получить роль по слагу (системную или кастомную)
     */
    public function findBySlug(string $slug, ?int $organizationId = null): ?array
    {
        // Сначала ищем в системных ролях
        $systemRole = $this->roleScanner->getRole($slug);
        if ($systemRole) {
            return array_merge($systemRole, ['type' => 'system']);
        }

        // Затем в кастомных ролях
        if ($organizationId) {
            $customRole = OrganizationCustomRole::where('slug', $slug)
                ->where('organization_id', $organizationId)
                ->active()
                ->first();
                
            if ($customRole) {
                return array_merge($customRole->toArray(), ['type' => 'custom']);
            }
        }

        return null;
    }

    /**
     * Получить все системные роли
     */
    public function getSystemRoles(): Collection
    {
        return $this->roleScanner->getAllRoles();
    }

    /**
     * Получить кастомные роли организации
     */
    public function getCustomRoles(int $organizationId): Collection
    {
        return OrganizationCustomRole::forOrganization($organizationId)
            ->active()
            ->get();
    }

    /**
     * Получить все роли для организации (системные + кастомные)
     */
    public function getAllRolesForOrganization(int $organizationId): array
    {
        $systemRoles = $this->getSystemRoles()->toArray();
        $customRoles = $this->getCustomRoles($organizationId)->toArray();

        return [
            'system' => $systemRoles,
            'custom' => $customRoles,
            'all' => array_merge(
                array_map(fn($role) => array_merge($role, ['type' => 'system']), $systemRoles),
                array_map(fn($role) => array_merge($role, ['type' => 'custom']), $customRoles)
            )
        ];
    }

    /**
     * Получить роли по контексту
     */
    public function getRolesByContext(string $context): Collection
    {
        return $this->roleScanner->getRolesByContext($context);
    }

    /**
     * Получить роли по интерфейсу
     */
    public function getRolesByInterface(string $interface): Collection
    {
        return $this->roleScanner->getRolesByInterface($interface);
    }

    /**
     * Проверить существование роли
     */
    public function exists(string $slug, ?int $organizationId = null): bool
    {
        return $this->findBySlug($slug, $organizationId) !== null;
    }

    /**
     * Получить права роли
     */
    public function getRolePermissions(string $slug, string $type = 'system', ?int $organizationId = null): array
    {
        if ($type === 'system') {
            $role = $this->roleScanner->getRole($slug);
            if (!$role) return [];

            return array_merge(
                $role['system_permissions'] ?? [],
                $this->flattenModulePermissions($role['module_permissions'] ?? [])
            );
        }

        if ($type === 'custom' && $organizationId) {
            $role = OrganizationCustomRole::where('slug', $slug)
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$role) return [];

            return array_merge(
                $role->system_permissions ?? [],
                $this->flattenModulePermissions($role->module_permissions ?? [])
            );
        }

        return [];
    }

    /**
     * Найти роли, которые может управлять указанная роль
     */
    public function getManagedRoles(string $managerRoleSlug): array
    {
        $role = $this->roleScanner->getRole($managerRoleSlug);
        if (!$role) return [];

        $hierarchy = $role['hierarchy'] ?? [];
        $canManage = $hierarchy['can_manage_roles'] ?? [];
        $cannotManage = $hierarchy['cannot_manage'] ?? [];

        if (in_array('*', $canManage)) {
            // Может управлять всеми, кроме исключений
            $allRoles = $this->getSystemRoles()->keys()->toArray();
            return array_diff($allRoles, $cannotManage);
        }

        return array_diff($canManage, $cannotManage);
    }

    /**
     * Очистить кеш ролей
     */
    public function clearCache(): void
    {
        $this->roleScanner->clearCache();
        Cache::tags(['custom_roles'])->flush();
    }

    /**
     * Преобразовать модульные права в плоский массив
     */
    protected function flattenModulePermissions(array $modulePermissions): array
    {
        $permissions = [];
        
        foreach ($modulePermissions as $module => $modulePerms) {
            foreach ($modulePerms as $permission) {
                if ($permission === '*') {
                    $permissions[] = "$module.*";
                } else {
                    $permissions[] = "$module.$permission";
                }
            }
        }
        
        return $permissions;
    }
}
