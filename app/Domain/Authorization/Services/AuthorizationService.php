<?php

namespace App\Domain\Authorization\Services;

use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Главный сервис авторизации
 */
class AuthorizationService
{
    protected RoleScanner $roleScanner;
    protected PermissionResolver $permissionResolver;
    protected LoggingService $logging;

    public function __construct(
        RoleScanner $roleScanner,
        PermissionResolver $permissionResolver,
        LoggingService $logging
    ) {
        $this->roleScanner = $roleScanner;
        $this->permissionResolver = $permissionResolver;
        $this->logging = $logging;
    }

    /**
     * Проверить, есть ли у пользователя право
     */
    public function can(User $user, string $permission, ?array $context = null): bool
    {
        static $callStack = [];
        
        $callKey = "{$user->id}:{$permission}:" . md5(serialize($context));
        
        if (isset($callStack[$callKey])) {
            $this->logging->security('auth.permission.circular_call_detected', [
                'user_id' => $user->id,
                'permission' => $permission,
                'context' => $context,
                'stack_depth' => count($callStack)
            ], 'error');
            return false;
        }
        
        $callStack[$callKey] = true;
        
        try {
            $cacheKey = "user_permission_{$user->id}_{$permission}_" . md5(serialize($context));
            
            $result = Cache::driver('array')->remember($cacheKey, 300, function () use ($user, $permission, $context) {
                return $this->checkPermission($user, $permission, $context);
            });

            $userAgent = request()->userAgent() ?? '';
            if (!str_contains($userAgent, 'Prometheus')) {
                if ($result) {
                    $this->logging->security('auth.permission.granted', [
                        'permission' => $permission,
                        'user_id' => $user->id,
                        'context' => $context
                    ]);
                } else {
                    $this->logging->security('auth.permission.denied', [
                        'permission' => $permission,
                        'user_id' => $user->id,
                        'context' => $context,
                        'email' => $user->email
                    ], 'warning');
                }
            }

            return $result;
        } finally {
            unset($callStack[$callKey]);
        }
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
        $cacheKey = "user_roles_{$user->id}_" . ($context ? $context->id : 'global');
        
        return Cache::driver('array')->remember($cacheKey, 300, function () use ($user, $context) {
            $query = $user->roleAssignments()
                ->active()
                ->with(['context.parentContext', 'customRole']);
            
            if ($context) {
                $contextIds = $this->getContextHierarchy($context)->pluck('id');
                $query->whereIn('context_id', $contextIds);
            }
            
            return $query->get();
        });
    }

    /**
     * Получить все права пользователя (плоский список)
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
     * Получить структурированные права пользователя (system + modules)
     */
    public function getUserPermissionsStructured(User $user, ?AuthorizationContext $context = null): array
    {
        $roles = $this->getUserRoles($user, $context);
        $systemPermissions = [];
        $modulePermissions = [];
        
        foreach ($roles as $assignment) {
            // Получаем системные права
            $systemPerms = $this->permissionResolver->getSystemPermissions($assignment);
            $systemPermissions = array_merge($systemPermissions, $systemPerms);
            
            // Получаем модульные права
            $modulePerms = $this->permissionResolver->getModulePermissions($assignment);
            foreach ($modulePerms as $module => $perms) {
                if (!isset($modulePermissions[$module])) {
                    $modulePermissions[$module] = [];
                }
                $modulePermissions[$module] = array_merge($modulePermissions[$module], $perms);
            }
        }
        
        // Убираем дубликаты
        $systemPermissions = array_unique($systemPermissions);
        foreach ($modulePermissions as $module => $perms) {
            $modulePermissions[$module] = array_unique($perms);
        }
        
        return [
            'system' => $systemPermissions,
            'modules' => $modulePermissions
        ];
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
        // Контекст назначающего передается в параметрах audit логирования

        // Проверяем существование роли
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM) {
            if (!$this->roleScanner->roleExists($roleSlug)) {
                $this->logging->security('auth.role.assign.failed', [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'role_slug' => $roleSlug,
                    'role_type' => $roleType,
                    'assigned_by' => $assignedBy?->id,
                    'context_type' => $context->type,
                    'context_id' => $context->id,
                    'error' => 'System role does not exist'
                ], 'error');
                throw new \InvalidArgumentException("Системная роль '$roleSlug' не существует");
            }
        } else {
            if (!OrganizationCustomRole::where('slug', $roleSlug)->exists()) {
                $this->logging->security('auth.role.assign.failed', [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'role_slug' => $roleSlug,
                    'role_type' => $roleType,
                    'assigned_by' => $assignedBy?->id,
                    'context_type' => $context->type,
                    'context_id' => $context->id,
                    'error' => 'Custom role does not exist'
                ], 'error');
                throw new \InvalidArgumentException("Кастомная роль '$roleSlug' не существует");
            }
        }

        $assignment = UserRoleAssignment::assignRole($user, $roleSlug, $context, $roleType, $assignedBy, $expiresAt);

        // AUDIT: Назначение роли - критически важное событие
        $this->logging->audit('auth.role.assigned', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'role_slug' => $roleSlug,
            'role_type' => $roleType,
            'assigned_by' => $assignedBy?->id,
            'assigned_by_email' => $assignedBy?->email,
            'context_type' => $context->type,
            'context_id' => $context->id,
            'expires_at' => $expiresAt?->toISOString(),
            'assignment_id' => $assignment->id
        ]);

        return $assignment;
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

        if ($assignment) {
            $result = $assignment->revoke();
            
            if ($result) {
                // AUDIT: Отзыв роли - критически важное событие
                $this->logging->audit('auth.role.revoked', [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'role_slug' => $roleSlug,
                    'role_type' => $assignment->role_type,
                    'revoked_by' => 'system', // TODO: добавить определение текущего пользователя
                    'context_type' => $context->type,
                    'context_id' => $context->id,
                    'assignment_id' => $assignment->id,
                    'was_active' => $assignment->is_active
                ]);
            } else {
                $this->logging->security('auth.role.revoke.failed', [
                    'target_user_id' => $user->id,
                    'role_slug' => $roleSlug,
                    'assignment_id' => $assignment->id,
                    'error' => 'Failed to revoke assignment'
                ], 'error');
            }
            
            return $result;
        }

        $this->logging->security('auth.role.revoke.notfound', [
            'target_user_id' => $user->id,
            'role_slug' => $roleSlug,
            'context_id' => $context->id
        ], 'warning');

        return false;
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
        $authContext = $this->resolveAuthContext($context);
        $roles = $this->getUserRoles($user, $authContext);
        
        if ($roles->isEmpty()) {
            $userAgent = request()->userAgent() ?? '';
            if (!str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('auth.no_roles_found', [
                    'user_id' => $user->id,
                    'permission_requested' => $permission,
                    'context' => $context
                ], 'info');
            }
            return false;
        }

        $userAgent = request()->userAgent() ?? '';
        if (!str_contains($userAgent, 'Prometheus')) {
            $userRoles = $roles->pluck('role_slug')->toArray();
            $this->logging->security('auth.checking_permission', [
                'user_id' => $user->id,
                'permission' => $permission,
                'user_roles' => $userRoles,
                'roles_count' => $roles->count(),
                'auth_context_type' => $authContext ? $authContext->type : null
            ], 'info');
        }

        foreach ($roles as $assignment) {
            if ($this->permissionResolver->hasPermission($assignment, $permission, $context)) {
                if ($this->evaluateConditions($assignment, $context ?? [])) {
                    if (!str_contains($userAgent, 'Prometheus')) {
                        $this->logging->security('auth.permission.resolved', [
                            'user_id' => $user->id,
                            'permission' => $permission,
                            'granted_by_role' => $assignment->role_slug,
                            'role_type' => $assignment->role_type
                        ], 'info');
                    }
                    return true;
                } else {
                    if (!str_contains($userAgent, 'Prometheus')) {
                        $this->logging->security('auth.conditions.failed', [
                            'user_id' => $user->id,
                            'permission' => $permission,
                            'role' => $assignment->role_slug,
                            'conditions_count' => $assignment->conditions()->active()->count()
                        ], 'warning');
                    }
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
