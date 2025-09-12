<?php

namespace App\Domain\Authorization\Services;

use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Главный сервис авторизации
 */
class AuthorizationService
{
    protected RoleScanner $roleScanner;
    protected PermissionResolver $permissionResolver;

    public function __construct(
        RoleScanner $roleScanner,
        PermissionResolver $permissionResolver
    ) {
        $this->roleScanner = $roleScanner;
        $this->permissionResolver = $permissionResolver;
    }

    /**
     * Проверить, есть ли у пользователя право
     */
    public function can(User $user, string $permission, ?array $context = null): bool
    {
        // Кешируем результат проверки на время запроса
        $cacheKey = "user_permission_{$user->id}_{$permission}_" . md5(serialize($context));
        
        return Cache::driver('array')->remember($cacheKey, 300, function () use ($user, $permission, $context) {
            return $this->checkPermission($user, $permission, $context);
        });
    }

    /**
     * Проверить, есть ли у пользователя роль
     */
    public function hasRole(User $user, string $roleSlug, ?int $contextId = null): bool
    {
        $query = $user->roleAssignments()->active()->where('role_slug', $roleSlug);
        
        if ($contextId) {
            $query->where('context_id', $contextId);
        }
        
        return $query->exists();
    }

    /**
     * Получить все роли пользователя в контексте
     */
    public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection
    {
        $query = $user->roleAssignments()->active()->with(['context', 'customRole']);
        
        if ($context) {
            // Получаем роли в указанном контексте и всех родительских контекстах
            $contextIds = $this->getContextHierarchy($context)->pluck('id');
            $query->whereIn('context_id', $contextIds);
        }
        
        return $query->get();
    }

    /**
     * Получить все права пользователя
     */
    public function getUserPermissions(User $user, ?AuthorizationContext $context = null): array
    {
        $roles = $this->getUserRoles($user, $context);
        $permissions = [];
        
        foreach ($roles as $assignment) {
            $rolePermissions = $this->getRolePermissions($assignment->role_slug, $assignment->role_type);
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        return array_unique($permissions);
    }

    /**
     * Назначить роль пользователю
     */
    public function assignRole(
        User $user,
        string $roleSlug,
        AuthorizationContext $context,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?User $assignedBy = null,
        ?\Carbon\Carbon $expiresAt = null
    ): UserRoleAssignment {
        // Проверяем существование роли
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM) {
            if (!$this->roleScanner->roleExists($roleSlug)) {
                throw new \InvalidArgumentException("Системная роль '$roleSlug' не существует");
            }
        } else {
            if (!OrganizationCustomRole::where('slug', $roleSlug)->exists()) {
                throw new \InvalidArgumentException("Кастомная роль '$roleSlug' не существует");
            }
        }

        return UserRoleAssignment::assignRole($user, $roleSlug, $context, $roleType, $assignedBy, $expiresAt);
    }

    /**
     * Отозвать роль у пользователя
     */
    public function revokeRole(User $user, string $roleSlug, AuthorizationContext $context): bool
    {
        $assignment = $user->roleAssignments()
            ->where('role_slug', $roleSlug)
            ->where('context_id', $context->id)
            ->first();

        return $assignment ? $assignment->revoke() : false;
    }

    /**
     * Проверить, может ли пользователь управлять другим пользователем
     */
    public function canManageUser(User $manager, User $target, AuthorizationContext $context): bool
    {
        $managerRoles = $this->getUserRoles($manager, $context);
        $targetRoles = $this->getUserRoles($target, $context);
        
        foreach ($managerRoles as $managerAssignment) {
            foreach ($targetRoles as $targetAssignment) {
                if ($this->roleScanner->canManageRole($managerAssignment->role_slug, $targetAssignment->role_slug)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Проверить доступ к интерфейсу
     */
    public function canAccessInterface(User $user, string $interface, ?AuthorizationContext $context = null): bool
    {
        $roles = $this->getUserRoles($user, $context);
        
        foreach ($roles as $assignment) {
            $interfaceAccess = $this->getRoleInterfaceAccess($assignment->role_slug, $assignment->role_type);
            if (in_array($interface, $interfaceAccess)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Получить контексты, в которых у пользователя есть роли
     */
    public function getUserContexts(User $user): Collection
    {
        return AuthorizationContext::whereHas('assignments', function ($query) use ($user) {
            $query->where('user_id', $user->id)->active();
        })->get();
    }

    /**
     * Проверка конкретного права
     */
    protected function checkPermission(User $user, string $permission, ?array $context = null): bool
    {
        // Определяем контекст авторизации
        $authContext = $this->resolveAuthContext($context);
        
        // Получаем роли пользователя
        $roles = $this->getUserRoles($user, $authContext);
        
        if ($roles->isEmpty()) {
            return false;
        }

        // Проверяем права через PermissionResolver
        foreach ($roles as $assignment) {
            if ($this->permissionResolver->hasPermission($assignment, $permission, $context)) {
                // Дополнительно проверяем условия (ABAC)
                if ($this->evaluateConditions($assignment, $context ?? [])) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Получить все права роли
     */
    protected function getRolePermissions(string $roleSlug, string $roleType): array
    {
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM) {
            return $this->permissionResolver->getSystemRolePermissions($roleSlug);
        } else {
            return $this->permissionResolver->getCustomRolePermissions($roleSlug);
        }
    }

    /**
     * Получить доступ к интерфейсам для роли
     */
    protected function getRoleInterfaceAccess(string $roleSlug, string $roleType): array
    {
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM) {
            return $this->roleScanner->getInterfaceAccess($roleSlug);
        } else {
            $role = OrganizationCustomRole::where('slug', $roleSlug)->first();
            return $role ? $role->interface_access : [];
        }
    }

    /**
     * Определить контекст авторизации из массива
     */
    protected function resolveAuthContext(?array $context): ?AuthorizationContext
    {
        if (!$context) {
            return null;
        }

        if (isset($context['project_id'])) {
            return AuthorizationContext::getProjectContext(
                $context['project_id'], 
                $context['organization_id']
            );
        }

        if (isset($context['organization_id'])) {
            return AuthorizationContext::getOrganizationContext($context['organization_id']);
        }

        return AuthorizationContext::getSystemContext();
    }

    /**
     * Получить иерархию контекста (от текущего к корню)
     */
    protected function getContextHierarchy(AuthorizationContext $context): Collection
    {
        return collect($context->getHierarchy());
    }

    /**
     * Оценить условия роли (ABAC)
     */
    protected function evaluateConditions(UserRoleAssignment $assignment, array $context): bool
    {
        $conditions = $assignment->conditions()->active()->get();
        
        foreach ($conditions as $condition) {
            if (!$condition->evaluate($context)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Получить слаги ролей пользователя для совместимости со старой системой
     */
    public function getUserRoleSlugs(User $user, ?array $context = null): array
    {
        try {
            $authContext = null;
            if ($context && isset($context['organization_id'])) {
                $authContext = AuthorizationContext::getOrganizationContext($context['organization_id']);
            }
            
            return $this->getUserRoles($user, $authContext)->pluck('role_slug')->toArray();
        } catch (\Exception $e) {
            // Если таблицы новой системы еще не созданы - возвращаем пустой массив
            return [];
        }
    }
}
