<?php

namespace App\Helpers;

use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\OrganizationCustomRole;

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
    public function getAdminPanelRoles(?int $organizationId = null, ?string $currentInterface = 'lk'): array
    {
        $roles = [];

        $systemRoles = $this->getSystemRolesByInterface($currentInterface);
        $roles = array_merge($roles, $systemRoles);

        $customRoles = [];
        if ($organizationId) {
            $customRoles = $this->getCustomRolesByInterface($organizationId, $currentInterface);
            $roles = array_merge($roles, $customRoles);
        }

        $finalRoles = array_unique($roles);
        
        // Логируем только в debug режиме или при ошибках, чтобы не замедлять работу
        if (config('app.debug')) {
            \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Getting roles for interface', [
                'current_interface' => $currentInterface,
                'organization_id' => $organizationId,
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
        if (!$this->roleScanner->roleExists($roleSlug)) {
            return false;
        }

        $systemPermissions = $this->roleScanner->getSystemPermissions($roleSlug);
        if (in_array('admin.access', $systemPermissions) || in_array('*', $systemPermissions)) {
            return true;
        }

        $interfaceAccess = $this->roleScanner->getInterfaceAccess($roleSlug);
        // Принимаем роли с доступом к админ-панели ИЛИ к личному кабинету
        return in_array('admin', $interfaceAccess) || in_array('lk', $interfaceAccess);
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
        // Принимаем роли с доступом к админ-панели ИЛИ к личному кабинету
        return in_array('admin', $interfaceAccess) || in_array('lk', $interfaceAccess);
    }

    /**
     * Получить разрешенные интерфейсы в зависимости от текущего интерфейса
     */
    protected function getAllowedInterfaces(string $currentInterface): array
    {
        return match($currentInterface) {
            'lk' => ['lk', 'mobile', 'admin'],      // ЛК может создавать для всех интерфейсов
            'admin' => ['admin', 'mobile'],         // Админка может создавать для админки и мобилки
            'mobile' => [],                         // Мобилка не может создавать пользователей
            default => ['lk']                       // По умолчанию только ЛК
        };
    }

    /**
     * Получить системные роли для указанного интерфейса
     */
    protected function getSystemRolesByInterface(string $currentInterface): array
    {
        $allowedInterfaces = $this->getAllowedInterfaces($currentInterface);
        
        if (empty($allowedInterfaces)) {
            return []; // Мобилка не может создавать пользователей
        }

        $roles = [];
        $allRoles = $this->roleScanner->getAllRoles();

        foreach ($allRoles as $slug => $role) {
            $systemPermissions = $role['system_permissions'] ?? [];
            $interfaceAccess = $role['interface_access'] ?? [];

            // Проверяем глобальные права
            if (in_array('admin.access', $systemPermissions) || in_array('*', $systemPermissions)) {
                $roles[] = $slug;
                continue;
            }

            // Проверяем доступ к разрешенным интерфейсам
            foreach ($allowedInterfaces as $allowedInterface) {
                if (in_array($allowedInterface, $interfaceAccess)) {
                    $roles[] = $slug;
                    break;
                }
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
        
        if (empty($allowedInterfaces)) {
            return []; // Мобилка не может создавать пользователей
        }

        $customRoles = OrganizationCustomRole::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();
            
        \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Checking custom roles for interface', [
            'organization_id' => $organizationId,
            'current_interface' => $currentInterface,
            'allowed_interfaces' => $allowedInterfaces,
            'total_custom_roles' => $customRoles->count(),
            'custom_roles_details' => $customRoles->map(function ($role) {
                return [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'system_permissions' => $role->system_permissions,
                    'interface_access' => $role->interface_access,
                    'has_admin_access' => in_array('admin.access', $role->system_permissions ?? []),
                    'has_wildcard' => in_array('*', $role->system_permissions ?? []),
                ];
            })->toArray()
        ]);
        
        $filteredRoles = $customRoles->filter(function ($role) use ($allowedInterfaces) {
            $systemPermissions = $role->system_permissions ?? [];
            $interfaceAccess = $role->interface_access ?? [];
            
            // Проверяем глобальные права
            if (in_array('admin.access', $systemPermissions) || in_array('*', $systemPermissions)) {
                return true;
            }
            
            // Проверяем доступ к разрешенным интерфейсам
            foreach ($allowedInterfaces as $allowedInterface) {
                if (in_array($allowedInterface, $interfaceAccess)) {
                    return true;
                }
            }
            
            return false;
        })->pluck('slug')->toArray();
        
        \Illuminate\Support\Facades\Log::info('[AdminPanelAccessHelper] Filtered custom roles for interface', [
            'organization_id' => $organizationId,
            'current_interface' => $currentInterface,
            'filtered_roles' => $filteredRoles
        ]);
        
        return $filteredRoles;
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
