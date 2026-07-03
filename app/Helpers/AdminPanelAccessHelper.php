<?php

namespace App\Helpers;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use Illuminate\Support\Facades\Log;

/**
 * Хелпер для проверки доступа пользователей к интерфейсам
 * (используется для создания пользователей из ЛК)
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
     * Получить все роли, которые могут быть созданы из текущего интерфейса
     */
    public function getAdminPanelRoles(
        ?int $organizationId = null,
        ?string $currentInterface = 'lk',
        bool $assignableOnly = false
    ): array
    {
        $roles = [];

        $systemRoles = $this->getSystemRolesByInterface($currentInterface ?? 'lk', $assignableOnly);
        $roles = array_merge($roles, $systemRoles);

        $customRoles = [];
        if ($organizationId) {
            $customRoles = $this->getCustomRolesByInterface($organizationId, $currentInterface ?? 'lk');
            $roles = array_merge($roles, $customRoles);
        }

        $finalRoles = array_values(array_unique($roles));
        
        // Логируем только в debug режиме или при ошибках, чтобы не замедлять работу
        if (config('app.debug')) {
            Log::info('[AdminPanelAccessHelper] Getting roles for interface', [
                'current_interface' => $currentInterface,
                'organization_id' => $organizationId,
                'assignable_only' => $assignableOnly,
                'system_roles' => $systemRoles,
                'custom_roles' => $customRoles,
                'final_roles' => $finalRoles
            ]);
        }

        return $finalRoles;
    }

    /**
     * Проверить системную роль
     */
    protected function checkSystemRole(string $roleSlug): bool
    {
        $role = $this->roleScanner->getRole($roleSlug);

        if (!$role) {
            return false;
        }

        return $this->systemRoleHasAdminPanelAccess($role, ['admin']);
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

        return $this->customRoleHasAdminPanelAccess($customRole, ['admin']);
    }

    /**
     * Получить разрешенные интерфейсы в зависимости от текущего интерфейса
     */
    protected function getAllowedInterfaces(string $currentInterface): array
    {
        return match ($currentInterface) {
            'lk', 'admin' => ['admin'],
            'mobile' => [],
            default => ['admin'],
        };
    }

    /**
     * Получить системные роли для указанного интерфейса
     */
    protected function getSystemRolesByInterface(string $currentInterface, bool $assignableOnly = false): array
    {
        $allowedInterfaces = $this->getAllowedInterfaces($currentInterface);
        
        if (empty($allowedInterfaces)) {
            return [];
        }

        $roles = [];
        $allRoles = $this->roleScanner->getAllRoles();

        foreach ($allRoles as $slug => $role) {
            if ($assignableOnly && ($role['assignable'] ?? true) === false) {
                continue;
            }

            if ($this->systemRoleHasAdminPanelAccess($role, $allowedInterfaces)) {
                $roles[] = $slug;
            }
        }

        return array_unique($roles);
    }

    /**
     * Получить все системные роли с доступом к админ-панели (устаревший метод)
     * @deprecated Используйте getSystemRolesByInterface
     */
    protected function getSystemAdminRoles(): array
    {
        return $this->getSystemRolesByInterface('lk');
    }

    /**
     * Получить кастомные роли для указанного интерфейса
     */
    protected function getCustomRolesByInterface(int $organizationId, string $currentInterface): array
    {
        $allowedInterfaces = $this->getAllowedInterfaces($currentInterface);
        if ($currentInterface === 'lk') {
            $allowedInterfaces[] = 'lk';
            $allowedInterfaces = array_values(array_unique($allowedInterfaces));
        }
        
        if (empty($allowedInterfaces)) {
            return [];
        }

        $customRoles = OrganizationCustomRole::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        $filteredRoles = $customRoles
            ->filter(fn (OrganizationCustomRole $role): bool => $this->customRoleHasAdminPanelAccess($role, $allowedInterfaces))
            ->pluck('slug')
            ->values()
            ->toArray();

        if (config('app.debug')) {
            Log::info('[AdminPanelAccessHelper] Filtered custom roles for interface', [
                'organization_id' => $organizationId,
                'current_interface' => $currentInterface,
                'filtered_roles' => $filteredRoles
            ]);
        }

        return $filteredRoles;
    }

    protected function systemRoleHasAdminPanelAccess(array $role, array $allowedInterfaces): bool
    {
        $systemPermissions = is_array($role['system_permissions'] ?? null) ? $role['system_permissions'] : [];
        $interfaceAccess = is_array($role['interface_access'] ?? null) ? $role['interface_access'] : [];

        if (in_array('admin.access', $systemPermissions, true) || in_array('*', $systemPermissions, true)) {
            return true;
        }

        foreach ($allowedInterfaces as $allowedInterface) {
            if (in_array($allowedInterface, $interfaceAccess, true)) {
                return true;
            }
        }

        return false;
    }

    protected function customRoleHasAdminPanelAccess(OrganizationCustomRole $role, array $allowedInterfaces): bool
    {
        $systemPermissions = is_array($role->system_permissions ?? null) ? $role->system_permissions : [];
        $interfaceAccess = is_array($role->interface_access ?? null) ? $role->interface_access : [];

        if (in_array('admin.access', $systemPermissions, true) || in_array('*', $systemPermissions, true)) {
            return true;
        }

        foreach ($allowedInterfaces as $allowedInterface) {
            if (in_array($allowedInterface, $interfaceAccess, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить все кастомные роли с доступом к админ-панели в организации (устаревший метод)
     * @deprecated Используйте getCustomRolesByInterface
     */
    protected function getCustomAdminRoles(int $organizationId): array
    {
        return $this->getCustomRolesByInterface($organizationId, 'lk');
    }
}
