<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

    protected PermissionResolver $permissionResolver;

    public function __construct(
        RoleScanner $roleScanner,
        ModulePermissionChecker $moduleChecker,
        AuthorizationService $authService,
        PermissionResolver $permissionResolver
    ) {
        $this->roleScanner = $roleScanner;
        $this->moduleChecker = $moduleChecker;
        $this->authService = $authService;
        $this->permissionResolver = $permissionResolver;
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
        if ($createdBy instanceof User) {
            $this->assertOrganizationPermission($createdBy, $organizationId, 'roles.create_custom');
        }

        return $this->createValidatedRole($organizationId, $name, $systemPermissions, $modulePermissions,
            $interfaceAccess, $conditions, $description, $createdBy);
    }

    public function createRoleForChildOrganization(
        int $organizationId,
        string $name,
        array $permissions,
        ?string $description,
        User $createdBy
    ): OrganizationCustomRole {
        $child = Organization::findOrFail($organizationId);
        if ($child->organization_type !== 'child') {
            throw new AuthorizationException(trans_message('landing.multi_organization.child_access_denied'));
        }

        if ((int) $createdBy->current_organization_id === $organizationId) {
            $this->assertOrganizationPermission($createdBy, $organizationId, 'roles.create_custom');
        } else {
            $parent = Organization::find($child->parent_organization_id);
            if ($parent === null || !$parent->is_holding
                || (int) $createdBy->current_organization_id !== (int) $parent->id) {
                throw new AuthorizationException(trans_message('landing.multi_organization.child_access_denied'));
            }
            $this->assertOrganizationPermission($createdBy, (int) $parent->id, 'multi-organization.manage_child_users');
        }

        $systemPermissions = [];
        $modulePermissions = [];
        $availableSystemPermissions = $this->getAvailableSystemPermissions($organizationId);
        $availableModulePermissions = $this->getAvailableModulePermissions($organizationId);
        foreach ($permissions as $permission) {
            if (isset($availableSystemPermissions[$permission])) {
                $systemPermissions[] = $permission;
                continue;
            }
            $module = null;
            foreach ($availableModulePermissions as $moduleKey => $available) {
                if (in_array($permission, $available, true)) {
                    $module = $moduleKey;
                    break;
                }
            }
            if ($module === null) {
                throw ValidationException::withMessages(['permissions' => "Недопустимое право: $permission"]);
            }
            $modulePermissions[$module][] = $permission;
        }

        return $this->createValidatedRole($organizationId, $name, $systemPermissions, $modulePermissions,
            ['lk'], null, $description, $createdBy);
    }

    private function createValidatedRole(
        int $organizationId,
        string $name,
        array $systemPermissions,
        array $modulePermissions,
        array $interfaceAccess,
        ?array $conditions,
        ?string $description,
        ?User $createdBy
    ): OrganizationCustomRole {
        $systemPermissions = RolePermissionNormalizer::normalizeSystemPermissions($systemPermissions, $interfaceAccess);
        $modulePermissions = RolePermissionNormalizer::normalizeModulePermissions($modulePermissions);

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
        ?User $updatedBy = null,
        ?int $organizationId = null
    ): bool {
        $this->assertRoleAccess($role, $updatedBy, $organizationId);

        // Валидируем новые права, если они переданы
        $shouldValidatePermissions = isset($data['system_permissions'])
            || isset($data['module_permissions'])
            || isset($data['interface_access']);
        $shouldValidateInterfaceAccess = isset($data['interface_access']);
        $data = $this->normalizeRoleData($role, $data);

        if ($shouldValidatePermissions) {
            $this->validatePermissions(
                $role->organization_id,
                $data['system_permissions'] ?? $role->system_permissions,
                $data['module_permissions'] ?? $role->module_permissions
            );
        }

        if ($shouldValidateInterfaceAccess) {
            $this->validateInterfaceAccess($data['interface_access']);
        }

        $userIds = $this->roleAssignmentUserIds($role);

        $updated = DB::transaction(function () use ($role, $data) {
            return $role->update($data);
        });

        if ($updated) {
            $this->clearCustomRolePermissionCache($role, $userIds);
        }

        return $updated;
    }

    /**
     * Удалить кастомную роль
     */
    public function deleteRole(OrganizationCustomRole $role, ?User $actor = null, ?int $organizationId = null): bool
    {
        $this->assertRoleAccess($role, $actor, $organizationId);

        $userIds = $this->roleAssignmentUserIds($role);

        $deleted = DB::transaction(function () use ($role) {
            // Деактивируем все назначения этой роли
            $role->assignments()->update(['is_active' => false]);

            // Удаляем роль
            return $role->delete();
        });

        if ($deleted) {
            $this->clearCustomRolePermissionCache($role, $userIds);
        }

        return $deleted;
    }

    /**
     * Клонировать роль
     */
    public function cloneRole(
        OrganizationCustomRole $sourceRole,
        int $targetOrganizationId,
        ?string $newName = null,
        ?User $createdBy = null,
        ?int $sourceOrganizationId = null
    ): OrganizationCustomRole {
        $this->assertRoleAccess($sourceRole, $createdBy, $sourceOrganizationId);
        $this->assertOrganizationPermission($createdBy, $targetOrganizationId, 'roles.create_custom');

        $name = $newName ?? ($sourceRole->name.' (копия)');

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
        if (! $role->is_active) {
            throw new \InvalidArgumentException('Нельзя назначить неактивную роль');
        }

        if (
            $context->type !== AuthorizationContext::TYPE_ORGANIZATION
            || (int) $context->resource_id !== (int) $role->organization_id
        ) {
            throw new \InvalidArgumentException(trans_message('permissions.invalid_organization_context'));
        }

        $assignment = $this->authService->assignRole(
            $user,
            $role->slug,
            $context,
            UserRoleAssignment::TYPE_CUSTOM,
            $assignedBy,
            $expiresAt
        );

        $this->permissionResolver->clearUserPermissionCache($user->id);

        return $assignment;
    }

    public function syncUserRoles(
        User $user,
        array $roleIds,
        AuthorizationContext $context,
        ?User $assignedBy = null
    ): Collection {
        if ($context->type !== AuthorizationContext::TYPE_ORGANIZATION || ! $context->resource_id) {
            throw ValidationException::withMessages([
                'context' => trans_message('permissions.invalid_organization_context'),
            ]);
        }

        $roleIds = collect($roleIds)
            ->map(static fn ($roleId): int => (int) $roleId)
            ->unique()
            ->values();

        $roles = OrganizationCustomRole::query()
            ->where('organization_id', $context->resource_id)
            ->whereIn('id', $roleIds)
            ->active()
            ->get();

        if ($roles->count() !== $roleIds->count()) {
            throw ValidationException::withMessages([
                'custom_role_ids' => trans_message('permissions.invalid_custom_roles'),
            ]);
        }

        $assignments = DB::transaction(function () use ($user, $roles, $context, $assignedBy): Collection {
            $roleSlugs = $roles->pluck('slug')->all();

            UserRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('context_id', $context->id)
                ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                ->whereNotIn('role_slug', $roleSlugs)
                ->update(['is_active' => false]);

            $activeAssignments = UserRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('context_id', $context->id)
                ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                ->active()
                ->pluck('role_slug')
                ->all();

            foreach ($roles as $role) {
                if (! in_array($role->slug, $activeAssignments, true)) {
                    $this->assignRoleToUser($role, $user, $context, $assignedBy);
                }
            }

            return UserRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('context_id', $context->id)
                ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                ->active()
                ->get();
        });

        $this->permissionResolver->clearUserPermissionCache($user->id);

        return $assignments;
    }

    /**
     * Получить пользователей с указанной ролью
     */
    public function getRoleUsers(OrganizationCustomRole $role, ?User $actor = null, ?int $organizationId = null): Collection
    {
        $this->assertRoleAccess($role, $actor, $organizationId, 'roles.view_custom');

        return User::whereHas('roleAssignments', function ($query) use ($role) {
            $query->where('custom_role_id', $role->id)
                ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
                ->active();
        })->get();
    }

    public function assertRoleAccess(
        OrganizationCustomRole $role,
        ?User $actor,
        ?int $organizationId = null,
        string $permission = 'roles.manage_custom'
    ): void {
        if ($organizationId !== null && (int) $role->organization_id !== $organizationId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('permissions.access_denied'));
        }

        $this->assertOrganizationPermission($actor, (int) $role->organization_id, $permission);
    }

    private function assertOrganizationPermission(?User $actor, int $organizationId, string $permission): void
    {
        if (! $actor instanceof User || $organizationId <= 0
            || ! $actor->organizations()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()
            || ! $this->authService->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('permissions.access_denied'));
        }
    }

    /**
     * Проверить, может ли пользователь создавать кастомные роли
     */
    public function canCreateRoles(User $user, int $organizationId): bool
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        return $this->authService->can($user, 'roles.create_custom', [
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Проверить, может ли пользователь управлять ролью
     */
    public function canManageRole(User $user, OrganizationCustomRole $role): bool
    {
        $context = AuthorizationContext::getOrganizationContext($role->organization_id);

        return $this->authService->can($user, 'roles.manage_custom', [
            'organization_id' => $role->organization_id,
        ]);
    }

    /**
     * Получить доступные системные права для организации
     */
    public function getAvailableSystemPermissions(int $organizationId): array
    {
        // Базовые системные права, которые можно назначать в кастомных ролях
        $permissions = [
            'admin.access' => 'Доступ к административной панели',
            'admin.view' => 'Просмотр административной панели',
            'dashboard.view' => 'Просмотр дашборда',
            'profile.view' => 'Просмотр профиля',
            'profile.edit' => 'Редактирование профиля',
            'organization.view' => 'Просмотр организации',
            'users.view' => 'Просмотр пользователей',
            'users.invite' => 'Приглашение пользователей',
            'roles.view_custom' => 'Просмотр кастомных ролей',
        ];

        foreach ($this->roleScanner->getSystemPermissions('organization_owner') as $permission) {
            if (str_starts_with($permission, 'legal_archive.')) {
                $permissions[$permission] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * Получить доступные модульные права для организации
     */
    public function getAvailableModulePermissions(int $organizationId): array
    {
        // Берем активные модули из AccessController (учитывает системные модули)
        $activeModules = $this->moduleChecker->getActiveModules($organizationId);
        $availablePermissions = [];

        foreach ($activeModules as $moduleSlug) {
            // Нормализуем слаг, если он приходит с дефисами
            $normalized = str_replace('-', '_', $moduleSlug);
            $permissions = $this->moduleChecker->getModulePermissions($moduleSlug);
            $availablePermissions[$normalized] = $permissions;
        }

        return $availablePermissions;
    }

    private function clearCustomRolePermissionCache(OrganizationCustomRole $role, array $userIds = []): void
    {
        $this->permissionResolver->clearRolePermissionCache(
            $role->slug,
            UserRoleAssignment::TYPE_CUSTOM,
            (int) $role->organization_id,
            $userIds
        );
    }

    private function roleAssignmentUserIds(OrganizationCustomRole $role): array
    {
        return UserRoleAssignment::query()
            ->where('role_slug', $role->slug)
            ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
            ->whereHas('context', function ($query) use ($role): void {
                $query->where('type', AuthorizationContext::TYPE_ORGANIZATION)
                    ->where('resource_id', $role->organization_id);
            })
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Валидировать права роли
     */
    protected function validatePermissions(int $organizationId, array $systemPermissions, array $modulePermissions): void
    {
        // Валидируем системные права (если переданы)
        if (! empty($systemPermissions)) {
            $availableSystemPermissions = array_keys($this->getAvailableSystemPermissions($organizationId));
            foreach ($systemPermissions as $permission) {
                if (! in_array($permission, $availableSystemPermissions, true)) {
                    throw ValidationException::withMessages([
                        'system_permissions' => "Недопустимое системное право: $permission",
                    ]);
                }
            }
        }

        // Валидируем модульные права (если переданы)
        if (! empty($modulePermissions)) {
            $availableModulePermissions = $this->getAvailableModulePermissions($organizationId);
            foreach ($modulePermissions as $module => $permissions) {
                $availableModuleKey = $this->resolveAvailableModuleKey($availableModulePermissions, (string) $module);

                if ($availableModuleKey === null) {
                    throw ValidationException::withMessages([
                        'module_permissions' => "Модуль '$module' не активирован для организации",
                    ]);
                }

                $moduleAvailablePermissions = $availableModulePermissions[$availableModuleKey];
                foreach ($permissions as $permission) {
                    if ($permission !== '*' && ! in_array($permission, $moduleAvailablePermissions)) {
                        throw ValidationException::withMessages([
                            'module_permissions' => "Недопустимое право '$permission' для модуля '$module'",
                        ]);
                    }
                }
            }
        }
    }

    private function resolveAvailableModuleKey(array $availableModulePermissions, string $module): ?string
    {
        foreach ($this->moduleKeyVariants($module) as $moduleKey) {
            if (isset($availableModulePermissions[$moduleKey])) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function moduleKeyVariants(string $module): array
    {
        return array_values(array_unique([
            $module,
            str_replace('-', '_', $module),
            str_replace('_', '-', $module),
        ]));
    }

    /**
     * Валидировать доступ к интерфейсам
     */
    protected function validateInterfaceAccess(array $interfaceAccess): void
    {
        $allowedInterfaces = ['lk', 'admin', 'mobile'];

        foreach ($interfaceAccess as $interface) {
            if (! in_array($interface, $allowedInterfaces)) {
                throw ValidationException::withMessages([
                    'interface_access' => "Недопустимый интерфейс: $interface",
                ]);
            }
        }
    }

    private function normalizeRoleData(OrganizationCustomRole $role, array $data): array
    {
        $interfaceAccess = $data['interface_access'] ?? ($role->interface_access ?? []);
        $systemPermissions = $data['system_permissions'] ?? ($role->system_permissions ?? []);
        $modulePermissions = $data['module_permissions'] ?? ($role->module_permissions ?? []);

        $data['system_permissions'] = RolePermissionNormalizer::normalizeSystemPermissions(
            $systemPermissions,
            $interfaceAccess
        );
        $data['module_permissions'] = RolePermissionNormalizer::normalizeModulePermissions($modulePermissions);

        return $data;
    }
}
