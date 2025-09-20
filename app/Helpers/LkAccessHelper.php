<?php

namespace App\Helpers;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;

/**
 * Хелпер для проверки доступа к личному кабинету (ЛК)
 */
class LkAccessHelper
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    /**
     * Проверить, может ли роль дать доступ к личному кабинету
     */
    public function canRoleAccessLk(string $roleSlug, ?int $organizationId = null): bool
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
     * Получить все роли, которые могут дать доступ к личному кабинету в организации
     */
    public function getLkRoles(?int $organizationId = null): array
    {
        $roles = [];

        $systemRoles = $this->getSystemLkRoles();
        $roles = array_merge($roles, $systemRoles);

        if ($organizationId) {
            $customRoles = $this->getCustomLkRoles($organizationId);
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
        return in_array('lk', $interfaceAccess);
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
        return in_array('lk', $interfaceAccess);
    }

    /**
     * Получить все системные роли с доступом к личному кабинету
     */
    protected function getSystemLkRoles(): array
    {
        $lkRoles = [];
        $allRoles = $this->roleScanner->getAllRoles();

        foreach ($allRoles as $slug => $role) {
            $interfaceAccess = $role['interface_access'] ?? [];

            if (in_array('lk', $interfaceAccess)) {
                $lkRoles[] = $slug;
            }
        }

        return $lkRoles;
    }

    /**
     * Получить все кастомные роли с доступом к личному кабинету в организации
     */
    protected function getCustomLkRoles(int $organizationId): array
    {
        return OrganizationCustomRole::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereJsonContains('interface_access', 'lk')
            ->pluck('slug')
            ->toArray();
    }
}
