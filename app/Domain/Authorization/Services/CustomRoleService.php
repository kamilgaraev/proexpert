<?php

namespace App\Domain\Authorization\Services;

use App\Models\User;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сервис для управления кастомными ролями организаций
 */
class CustomRoleService
{
    protected RoleScanner $roleScanner;
    protected ModulePermissionChecker $moduleChecker;
    protected AuthorizationService $authService;

    public function __construct(
        RoleScanner $roleScanner,
        ModulePermissionChecker $moduleChecker,
        AuthorizationService $authService
    ) {
        $this->roleScanner = $roleScanner;
        $this->moduleChecker = $moduleChecker;
        $this->authService = $authService;
    }

    /**
     * Создать кастомную роль
     */
    public function createRole(
        int $organizationId,
        string $name,
        array $systemPermissions = [],
        array $modulePermissions = [],
        array $interfaceAccess = ['lk'],
        ?array $conditions = null,
        ?string $description = null,
        ?User $createdBy = null
    ): OrganizationCustomRole {
        // Валидируем права
        $this->validatePermissions($organizationId, $systemPermissions, $modulePermissions);
        
        // Валидируем интерфейсы
        $this->validateInterfaceAccess($interfaceAccess);

        return DB::transaction(function () use (
            $organizationId, $name, $systemPermissions, $modulePermissions, 
            $interfaceAccess, $conditions, $description, $createdBy
        ) {
            return OrganizationCustomRole::createRole(
                $organizationId,
                $name,
                $systemPermissions,
                $modulePermissions,
                $interfaceAccess,
                $conditions,
                $description,
                $createdBy
            );
        });
    }

    /**
     * Обновить кастомную роль
     */
    public function updateRole(
        OrganizationCustomRole $role,
        array $data,
        ?User $updatedBy = null
    ): bool {
        // Валидируем новые права, если они переданы
        if (isset($data['system_permissions']) || isset($data['module_permissions'])) {
            $this->validatePermissions(
                $role->organization_id,
                $data['system_permissions'] ?? $role->system_permissions,
                $data['module_permissions'] ?? $role->module_permissions
            );
        }

        if (isset($data['interface_access'])) {
            $this->validateInterfaceAccess($data['interface_access']);
        }

        return DB::transaction(function () use ($role, $data) {
            return $role->update($data);
        });
    }

    /**
     * Удалить кастомную роль
     */
    public function deleteRole(OrganizationCustomRole $role): bool
    {
        return DB::transaction(function () use ($role) {
            // Деактивируем все назначения этой роли
            $role->assignments()->update(['is_active' => false]);
            
            // Удаляем роль
            return $role->delete();
        });
    }

    /**
     * Клонировать роль
     */
    public function cloneRole(
        OrganizationCustomRole $sourceRole,
        int $targetOrganizationId,
        ?string $newName = null,
        ?User $createdBy = null
    ): OrganizationCustomRole {
        $name = $newName ?? ($sourceRole->name . ' (копия)');
        
        return $this->createRole(
            $targetOrganizationId,
            $name,
            $sourceRole->system_permissions,
            $sourceRole->module_permissions,
            $sourceRole->interface_access,
            $sourceRole->conditions,
            $sourceRole->description,
            $createdBy
        );
    }

    /**
     * Получить все роли организации
     */
    public function getOrganizationRoles(int $organizationId, bool $activeOnly = true): Collection
    {
        $query = OrganizationCustomRole::forOrganization($organizationId);
        
        if ($activeOnly) {
            $query->active();
        }
        
        return $query->orderBy('name')->get();
    }

    /**
     * Назначить кастомную роль пользователю
     */
    public function assignRoleToUser(
        OrganizationCustomRole $role,
        User $user,
        AuthorizationContext $context,
        ?User $assignedBy = null,
        ?\Carbon\Carbon $expiresAt = null
    ): UserRoleAssignment {
        if (!$role->is_active) {
            throw new \InvalidArgumentException('Нельзя назначить неактивную роль');
        }

        return $this->authService->assignRole(
            $user,
            $role->slug,
            $context,
            UserRoleAssignment::TYPE_CUSTOM,
            $assignedBy,
            $expiresAt
        );
    }

    /**
     * Получить пользователей с указанной ролью
     */
    public function getRoleUsers(OrganizationCustomRole $role): Collection
    {
        return User::whereHas('roleAssignments', function ($query) use ($role) {
            $query->where('role_slug', $role->slug)
                ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                ->active();
        })->get();
    }

    /**
     * Проверить, может ли пользователь создавать кастомные роли
     */
    public function canCreateRoles(User $user, int $organizationId): bool
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        return $this->authService->can($user, 'roles.create_custom', [
            'organization_id' => $organizationId
        ]);
    }

    /**
     * Проверить, может ли пользователь управлять ролью
     */
    public function canManageRole(User $user, OrganizationCustomRole $role): bool
    {
        $context = AuthorizationContext::getOrganizationContext($role->organization_id);
        return $this->authService->can($user, 'roles.manage_custom', [
            'organization_id' => $role->organization_id
        ]);
    }

    /**
     * Получить доступные системные права для организации
     */
    public function getAvailableSystemPermissions(int $organizationId): array
    {
        // Базовые системные права, которые можно назначать в кастомных ролях
        return [
            'profile.view' => 'Просмотр профиля',
            'profile.edit' => 'Редактирование профиля',
            'organization.view' => 'Просмотр организации',
            'users.view' => 'Просмотр пользователей',
            'users.invite' => 'Приглашение пользователей',
            'roles.view_custom' => 'Просмотр кастомных ролей',
        ];
    }

    /**
     * Получить доступные модульные права для организации
     */
    public function getAvailableModulePermissions(int $organizationId): array
    {
        $activeModules = $this->moduleChecker->getActiveModules($organizationId);
        $availablePermissions = [];
        
        foreach ($activeModules as $moduleSlug) {
            $permissions = $this->moduleChecker->getModulePermissions($moduleSlug);
            $availablePermissions[$moduleSlug] = $permissions;
        }
        
        return $availablePermissions;
    }

    /**
     * Валидировать права роли
     */
    protected function validatePermissions(int $organizationId, array $systemPermissions, array $modulePermissions): void
    {
        // Валидируем системные права
        $availableSystemPermissions = array_keys($this->getAvailableSystemPermissions($organizationId));
        foreach ($systemPermissions as $permission) {
            if ($permission !== '*' && !in_array($permission, $availableSystemPermissions)) {
                throw ValidationException::withMessages([
                    'system_permissions' => "Недопустимое системное право: $permission"
                ]);
            }
        }

        // Валидируем модульные права
        $availableModulePermissions = $this->getAvailableModulePermissions($organizationId);
        foreach ($modulePermissions as $module => $permissions) {
            if (!isset($availableModulePermissions[$module])) {
                throw ValidationException::withMessages([
                    'module_permissions' => "Модуль '$module' не активирован для организации"
                ]);
            }
            
            $moduleAvailablePermissions = $availableModulePermissions[$module];
            foreach ($permissions as $permission) {
                if ($permission !== '*' && !in_array($permission, $moduleAvailablePermissions)) {
                    throw ValidationException::withMessages([
                        'module_permissions' => "Недопустимое право '$permission' для модуля '$module'"
                    ]);
                }
            }
        }
    }

    /**
     * Валидировать доступ к интерфейсам
     */
    protected function validateInterfaceAccess(array $interfaceAccess): void
    {
        $allowedInterfaces = ['lk', 'admin', 'mobile'];
        
        foreach ($interfaceAccess as $interface) {
            if (!in_array($interface, $allowedInterfaces)) {
                throw ValidationException::withMessages([
                    'interface_access' => "Недопустимый интерфейс: $interface"
                ]);
            }
        }
    }
}
