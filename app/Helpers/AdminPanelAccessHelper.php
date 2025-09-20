<?php

namespace App\Helpers;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;

/**
 * Хелпер для проверки доступа к админ-панели
 */
class AdminPanelAccessHelper
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    /**
     * Проверить, может ли роль дать доступ к админ-панели
     */
    public function canRoleAccessAdminPanel(string $roleSlug, ?int $organizationId = null): bool
    {
        if ($this->checkSystemRole($roleSlug)) {
            return true;
        }

        if ($organizationId && $this->checkCustomRole($roleSlug, $organizationId)) {
            return true;
        }

        return false;
    }

    /**
     * Получить все роли, которые могут дать доступ к админ-панели в организации
     */
    public function getAdminPanelRoles(?int $organizationId = null): array
    {
        $roles = [];

        $systemRoles = $this->getSystemAdminRoles();
        $roles = array_merge($roles, $systemRoles);

        if ($organizationId) {
            $customRoles = $this->getCustomAdminRoles($organizationId);
            $roles = array_merge($roles, $customRoles);
        }

        $finalRoles = array_unique($roles);
        
        \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Getting admin panel roles', [
            'organization_id' => $organizationId,
            'system_roles' => $systemRoles,
            'custom_roles' => $organizationId ? $this->getCustomAdminRoles($organizationId) : [],
            'final_roles' => $finalRoles
        ]);

        return $finalRoles;
    }

    /**
     * Проверить системную роль
     */
    protected function checkSystemRole(string $roleSlug): bool
    {
        if (!$this->roleScanner->roleExists($roleSlug)) {
            return false;
        }

        $systemPermissions = $this->roleScanner->getSystemPermissions($roleSlug);
        if (in_array('admin.access', $systemPermissions) || in_array('*', $systemPermissions)) {
            return true;
        }

        $interfaceAccess = $this->roleScanner->getInterfaceAccess($roleSlug);
        return in_array('admin', $interfaceAccess);
    }

    /**
     * Проверить кастомную роль
     */
    protected function checkCustomRole(string $roleSlug, int $organizationId): bool
    {
        $customRole = OrganizationCustomRole::where('slug', $roleSlug)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();

        if (!$customRole) {
            return false;
        }

        $systemPermissions = $customRole->system_permissions ?? [];
        if (in_array('admin.access', $systemPermissions) || in_array('*', $systemPermissions)) {
            return true;
        }

        $interfaceAccess = $customRole->interface_access ?? [];
        return in_array('admin', $interfaceAccess);
    }

    /**
     * Получить все системные роли с доступом к админ-панели
     */
    protected function getSystemAdminRoles(): array
    {
        $adminRoles = [];
        $allRoles = $this->roleScanner->getAllRoles();

        foreach ($allRoles as $slug => $role) {
            $systemPermissions = $role['system_permissions'] ?? [];
            $interfaceAccess = $role['interface_access'] ?? [];

            if (in_array('admin.access', $systemPermissions) || 
                in_array('*', $systemPermissions) ||
                in_array('admin', $interfaceAccess)) {
                $adminRoles[] = $slug;
            }
        }

        return $adminRoles;
    }

    /**
     * Получить все кастомные роли с доступом к админ-панели в организации
     */
    protected function getCustomAdminRoles(int $organizationId): array
    {
        $customRoles = OrganizationCustomRole::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();
            
        \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Checking custom roles', [
            'organization_id' => $organizationId,
            'total_custom_roles' => $customRoles->count(),
            'custom_roles_details' => $customRoles->map(function ($role) {
                return [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'system_permissions' => $role->system_permissions,
                    'interface_access' => $role->interface_access,
                    'has_admin_access' => in_array('admin.access', $role->system_permissions ?? []),
                    'has_wildcard' => in_array('*', $role->system_permissions ?? []),
                    'has_admin_interface' => in_array('admin', $role->interface_access ?? [])
                ];
            })->toArray()
        ]);
        
        $filteredRoles = $customRoles->filter(function ($role) {
            $systemPermissions = $role->system_permissions ?? [];
            $interfaceAccess = $role->interface_access ?? [];
            
            return in_array('admin.access', $systemPermissions) ||
                   in_array('*', $systemPermissions) ||
                   in_array('admin', $interfaceAccess);
        })->pluck('slug')->toArray();
        
        \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Filtered custom admin roles', [
            'organization_id' => $organizationId,
            'filtered_roles' => $filteredRoles
        ]);
        
        return $filteredRoles;
    }
}
