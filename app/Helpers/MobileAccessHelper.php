<?php

namespace App\Helpers;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;

/**
 * Хелпер для проверки доступа к мобильному приложению
 */
class MobileAccessHelper
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    /**
     * Проверить, может ли роль дать доступ к мобильному приложению
     */
    public function canRoleAccessMobile(string $roleSlug, ?int $organizationId = null): bool
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
     * Получить все роли, которые могут дать доступ к мобильному приложению в организации
     */
    public function getMobileRoles(?int $organizationId = null): array
    {
        $roles = [];

        $systemRoles = $this->getSystemMobileRoles();
        $roles = array_merge($roles, $systemRoles);

        if ($organizationId) {
            $customRoles = $this->getCustomMobileRoles($organizationId);
            $roles = array_merge($roles, $customRoles);
        }

        return array_unique($roles);
    }

    /**
     * Проверить системную роль
     */
    protected function checkSystemRole(string $roleSlug): bool
    {
        if (!$this->roleScanner->roleExists($roleSlug)) {
            return false;
        }

        $interfaceAccess = $this->roleScanner->getInterfaceAccess($roleSlug);
        return in_array('mobile', $interfaceAccess);
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

        $interfaceAccess = $customRole->interface_access ?? [];
        return in_array('mobile', $interfaceAccess);
    }

    /**
     * Получить все системные роли с доступом к мобильному приложению
     */
    protected function getSystemMobileRoles(): array
    {
        $mobileRoles = [];
        $allRoles = $this->roleScanner->getAllRoles();

        foreach ($allRoles as $slug => $role) {
            $interfaceAccess = $role['interface_access'] ?? [];

            if (in_array('mobile', $interfaceAccess)) {
                $mobileRoles[] = $slug;
            }
        }

        return $mobileRoles;
    }

    /**
     * Получить все кастомные роли с доступом к мобильному приложению в организации
     */
    protected function getCustomMobileRoles(int $organizationId): array
    {
        return OrganizationCustomRole::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereJsonContains('interface_access', 'mobile')
            ->pluck('slug')
            ->toArray();
    }
}
