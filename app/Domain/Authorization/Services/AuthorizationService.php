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
 * Р“Р»Р°РІРЅС‹Р№ СЃРµСЂРІРёСЃ Р°РІС‚РѕСЂРёР·Р°С†РёРё
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
     * РџСЂРѕРІРµСЂРёС‚СЊ, РµСЃС‚СЊ Р»Рё Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РїСЂР°РІРѕ
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
     * РџСЂРѕРІРµСЂРёС‚СЊ, РµСЃС‚СЊ Р»Рё Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ СЂРѕР»СЊ
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
     * РџРѕР»СѓС‡РёС‚СЊ РІСЃРµ СЂРѕР»Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РІ РєРѕРЅС‚РµРєСЃС‚Рµ
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
                
                // Р”Р»СЏ РїСЂРѕРµРєС‚РЅС‹С… РєРѕРЅС‚РµРєСЃС‚РѕРІ С‚Р°РєР¶Рµ РґРѕР±Р°РІР»СЏРµРј РІСЃРµ РїСЂРѕРµРєС‚РЅС‹Рµ РєРѕРЅС‚РµРєСЃС‚С‹ РѕСЂРіР°РЅРёР·Р°С†РёРё
                // (СЂРѕР»Рё РјРѕРіСѓС‚ Р±С‹С‚СЊ РЅР°Р·РЅР°С‡РµРЅС‹ РІ СЂР°Р·РЅС‹С… РїСЂРѕРµРєС‚РЅС‹С… РєРѕРЅС‚РµРєСЃС‚Р°С…)
                if ($context->type === AuthorizationContext::TYPE_PROJECT && $context->parent_context_id) {
                    try {
                        $orgContext = AuthorizationContext::find($context->parent_context_id);
                        if ($orgContext) {
                            $projectContexts = AuthorizationContext::where('parent_context_id', $orgContext->id)
                                ->where('type', AuthorizationContext::TYPE_PROJECT)
                                ->pluck('id');
                            $contextIds = $contextIds->merge($projectContexts)->unique();
                        }
                    } catch (\Exception $e) {
                        // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РїСЂРё РїРѕРёСЃРєРµ РєРѕРЅС‚РµРєСЃС‚РѕРІ - РёСЃРїРѕР»СЊР·СѓРµРј С‚РѕР»СЊРєРѕ РёРµСЂР°СЂС…РёСЋ
                    }
                }
                
                $query->whereIn('context_id', $contextIds);
            }
            
            return $query->get();
        });
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІСЃРµ РїСЂР°РІР° РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ (РїР»РѕСЃРєРёР№ СЃРїРёСЃРѕРє)
     */
    public function getUserPermissions(User $user, ?AuthorizationContext $context = null): array
    {
        $roles = $this->getUserRoles($user, $context);
        $permissions = [];
        
        foreach ($roles as $assignment) {
            $orgId = $this->permissionResolver->extractOrganizationId($assignment);
            $rolePermissions = $this->getRolePermissions($assignment->role_slug, $assignment->role_type, $orgId);
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        return array_unique($permissions);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃС‚СЂСѓРєС‚СѓСЂРёСЂРѕРІР°РЅРЅС‹Рµ РїСЂР°РІР° РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ (system + modules)
     */
    public function getUserPermissionsStructured(User $user, ?AuthorizationContext $context = null): array
    {
        $roles = $this->getUserRoles($user, $context);
        $systemPermissions = [];
        $modulePermissions = [];
        
        foreach ($roles as $assignment) {
            // РџРѕР»СѓС‡Р°РµРј СЃРёСЃС‚РµРјРЅС‹Рµ РїСЂР°РІР°
            $systemPerms = $this->permissionResolver->getSystemPermissions($assignment);
            $systemPermissions = array_merge($systemPermissions, $systemPerms);
            
            // РџРѕР»СѓС‡Р°РµРј РјРѕРґСѓР»СЊРЅС‹Рµ РїСЂР°РІР°
            $modulePerms = $this->permissionResolver->getModulePermissions($assignment);
            foreach ($modulePerms as $module => $perms) {
                if (!isset($modulePermissions[$module])) {
                    $modulePermissions[$module] = [];
                }
                $modulePermissions[$module] = array_merge($modulePermissions[$module], $perms);
            }
        }
        
        // РЈР±РёСЂР°РµРј РґСѓР±Р»РёРєР°С‚С‹
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
     * РќР°Р·РЅР°С‡РёС‚СЊ СЂРѕР»СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ
     */
    public function assignRole(
        User $user,
        string $roleSlug,
        AuthorizationContext $context,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?User $assignedBy = null,
        ?\Carbon\Carbon $expiresAt = null
    ): UserRoleAssignment {
        // РљРѕРЅС‚РµРєСЃС‚ РЅР°Р·РЅР°С‡Р°СЋС‰РµРіРѕ РїРµСЂРµРґР°РµС‚СЃСЏ РІ РїР°СЂР°РјРµС‚СЂР°С… audit Р»РѕРіРёСЂРѕРІР°РЅРёСЏ

        // РџСЂРѕРІРµСЂСЏРµРј СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ СЂРѕР»Рё
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
                throw new \InvalidArgumentException("РЎРёСЃС‚РµРјРЅР°СЏ СЂРѕР»СЊ '$roleSlug' РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚");
            }
        } else {
            $organizationId = $context->type === AuthorizationContext::TYPE_ORGANIZATION
                ? $context->resource_id
                : null;

            if (
                !$organizationId
                || !OrganizationCustomRole::where('slug', $roleSlug)
                    ->where('organization_id', $organizationId)
                    ->active()
                    ->exists()
            ) {
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
                throw new \InvalidArgumentException("РљР°СЃС‚РѕРјРЅР°СЏ СЂРѕР»СЊ '$roleSlug' РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚");
            }
        }

        $assignment = UserRoleAssignment::assignRole($user, $roleSlug, $context, $roleType, $assignedBy, $expiresAt);

        // AUDIT: РќР°Р·РЅР°С‡РµРЅРёРµ СЂРѕР»Рё - РєСЂРёС‚РёС‡РµСЃРєРё РІР°Р¶РЅРѕРµ СЃРѕР±С‹С‚РёРµ
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
     * РћС‚РѕР·РІР°С‚СЊ СЂРѕР»СЊ Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
     */
    public function revokeRole(User $user, string $roleSlug, AuthorizationContext $context, ?User $revokedBy = null): bool
    {
        $assignment = $user->roleAssignments()
            ->where('role_slug', $roleSlug)
            ->where('context_id', $context->id)
            ->first();

        if ($assignment) {
            $result = $assignment->revoke();
            
            if ($result) {
                // AUDIT: РћС‚Р·С‹РІ СЂРѕР»Рё - РєСЂРёС‚РёС‡РµСЃРєРё РІР°Р¶РЅРѕРµ СЃРѕР±С‹С‚РёРµ
                $this->logging->audit('auth.role.revoked', [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'role_slug' => $roleSlug,
                    'role_type' => $assignment->role_type,
                    'revoked_by' => $revokedBy?->id,
                    'revoked_by_email' => $revokedBy?->email,
                    'revoked_by_type' => $revokedBy ? 'user' : 'system',
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
     * РџСЂРѕРІРµСЂРёС‚СЊ, РјРѕР¶РµС‚ Р»Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ СѓРїСЂР°РІР»СЏС‚СЊ РґСЂСѓРіРёРј РїРѕР»СЊР·РѕРІР°С‚РµР»РµРј
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
     * РџСЂРѕРІРµСЂРёС‚СЊ РґРѕСЃС‚СѓРї Рє РёРЅС‚РµСЂС„РµР№СЃСѓ
     */
    public function canAccessInterface(User $user, string $interface, ?AuthorizationContext $context = null): bool
    {
        $roles = $this->getUserRoles($user, $context);
        
        foreach ($roles as $assignment) {
            $orgId = $this->permissionResolver->extractOrganizationId($assignment);
            $interfaceAccess = $this->getRoleInterfaceAccess($assignment->role_slug, $assignment->role_type, $orgId);
            if (in_array($interface, $interfaceAccess)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РєРѕРЅС‚РµРєСЃС‚С‹, РІ РєРѕС‚РѕСЂС‹С… Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РµСЃС‚СЊ СЂРѕР»Рё
     */
    public function getUserContexts(User $user): Collection
    {
        return AuthorizationContext::whereHas('assignments', function ($query) use ($user) {
            $query->where('user_id', $user->id)->active();
        })->get();
    }

    /**
     * РџСЂРѕРІРµСЂРєР° РєРѕРЅРєСЂРµС‚РЅРѕРіРѕ РїСЂР°РІР°
     */
    protected function checkPermission(User $user, string $permission, ?array $context = null): bool
    {
        // Р•СЃР»Рё РєРѕРЅС‚РµРєСЃС‚ РЅРµ РїРµСЂРµРґР°РЅ, РЅРѕ РµСЃС‚СЊ РјРѕРґСѓР»СЊРЅРѕРµ РїСЂР°РІРѕ - РѕРїСЂРµРґРµР»СЏРµРј РєРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё
        if (!$context && $user->current_organization_id) {
            // РџСЂРѕРІРµСЂСЏРµРј, СЏРІР»СЏРµС‚СЃСЏ Р»Рё РїСЂР°РІРѕ РјРѕРґСѓР»СЊРЅС‹Рј (СЃРѕРґРµСЂР¶РёС‚ С‚РѕС‡РєСѓ)
            if (strpos($permission, '.') !== false) {
                $parts = explode('.', $permission, 2);
                $module = $parts[0];
                
                // Р”Р»СЏ РјРѕРґСѓР»СЊРЅС‹С… РїСЂР°РІ РёСЃРїРѕР»СЊР·СѓРµРј РєРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё
                $context = [
                    'context_type' => 'organization',
                    'organization_id' => $user->current_organization_id
                ];
            }
        }
        
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
                        ], 'warning');
                    }
                }
            }
        }

        // РџСЂРѕРІРµСЂРєР° СЂРѕРґРёС‚РµР»СЊСЃРєРёС… РѕСЂРіР°РЅРёР·Р°С†РёР№ РґР»СЏ РѕСЂРіР°РЅРёР·Р°С†РёРѕРЅРЅС‹С… РєРѕРЅС‚РµРєСЃС‚РѕРІ
        if ($authContext && $authContext->type === AuthorizationContext::TYPE_ORGANIZATION) {
            $cacheKey = "org_parent_{$authContext->resource_id}";
            $orgData = Cache::driver('array')->remember($cacheKey, 300, function () use ($authContext) {
                return \App\Models\Organization::where('id', $authContext->resource_id)
                    ->select('id', 'parent_organization_id')
                    ->first();
            });

            if ($orgData && $orgData->parent_organization_id) {
                $parentContextCacheKey = "org_context_{$orgData->parent_organization_id}";
                $parentContext = Cache::driver('array')->remember($parentContextCacheKey, 300, function () use ($orgData) {
                    return AuthorizationContext::getOrganizationContext($orgData->parent_organization_id);
                });

                if ($parentContext && $this->checkPermissionInContext($user, $permission, $parentContext)) {
                    return true;
                }
            }
        }
        
        // Р”Р»СЏ РїСЂРѕРµРєС‚РЅС‹С… РєРѕРЅС‚РµРєСЃС‚РѕРІ С‚Р°РєР¶Рµ РїСЂРѕРІРµСЂСЏРµРј РєРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё (СЂРѕР»Рё РјРѕРіСѓС‚ Р±С‹С‚СЊ РЅР°Р·РЅР°С‡РµРЅС‹ С‚Р°Рј)
        if ($authContext && $authContext->type === AuthorizationContext::TYPE_PROJECT && $authContext->parent_context_id) {
            try {
                $orgContext = AuthorizationContext::find($authContext->parent_context_id);
                if ($orgContext && $this->checkPermissionInContext($user, $permission, $orgContext)) {
                    return true;
                }
            } catch (\Exception $e) {
                // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РїСЂРё РїРѕРёСЃРєРµ РєРѕРЅС‚РµРєСЃС‚Р° РѕСЂРіР°РЅРёР·Р°С†РёРё
            }
        }
        
        return false;
    }

    protected function checkPermissionInContext(User $user, string $permission, AuthorizationContext $context): bool
    {
        $roles = $this->getUserRoles($user, $context);

        foreach ($roles as $assignment) {
            if ($this->permissionResolver->hasPermission($assignment, $permission, null)) {
                if ($this->evaluateConditions($assignment, [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІСЃРµ РїСЂР°РІР° СЂРѕР»Рё
     */
    protected function getRolePermissions(string $roleSlug, string $roleType, ?int $organizationId = null): array
    {
        // 1. РџС‹С‚Р°РµРјСЃСЏ РїРѕР»СѓС‡РёС‚СЊ СЃРёСЃС‚РµРјРЅС‹Рµ РїСЂР°РІР° РёР· С„Р°Р№Р»РѕРІ (РµСЃР»Рё С‚РёРї system РёР»Рё РµСЃР»Рё РЅРµ СѓРІРµСЂРµРЅС‹)
        $permissions = [];
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM || empty($roleType)) {
            $permissions = $this->roleScanner->getSystemPermissions($roleSlug);
        }

        // 2. Р•СЃР»Рё СЃРёСЃС‚РµРјРЅС‹С… РїСЂР°РІ РЅРµС‚, РёС‰РµРј РІ РєР°СЃС‚РѕРјРЅС‹С… СЂРѕР»СЏС… РІ Р‘Р”
        if (empty($permissions)) {
            return $this->permissionResolver->getCustomRolePermissions($roleSlug, $organizationId);
        }

        return $permissions;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРѕСЃС‚СѓРї Рє РёРЅС‚РµСЂС„РµР№СЃР°Рј РґР»СЏ СЂРѕР»Рё
     */
    protected function getRoleInterfaceAccess(string $roleSlug, string $roleType, ?int $organizationId = null): array
    {
        // 1. РџС‹С‚Р°РµРјСЃСЏ РїРѕР»СѓС‡РёС‚СЊ РґРѕСЃС‚СѓРї РёР· СЃРёСЃС‚РµРјРЅС‹С… С„Р°Р№Р»РѕРІ
        $access = [];
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM || empty($roleType)) {
            $access = $this->roleScanner->getInterfaceAccess($roleSlug);
        }

        // 2. Р•СЃР»Рё РІ С„Р°Р№Р»Р°С… РЅРёС‡РµРіРѕ РЅРµ РЅР°Р№РґРµРЅРѕ, РёС‰РµРј РІ РєР°СЃС‚РѕРјРЅС‹С… СЂРѕР»СЏС… (Р‘Р”)
        if (empty($access)) {
            $query = OrganizationCustomRole::where('slug', $roleSlug);
            
            if ($organizationId) {
                $query->where('organization_id', $organizationId);
            }
            
            $role = $query->first();
            return $role ? ($role->interface_access ?? []) : [];
        }

        return $access;
    }

    /**
     * РћРїСЂРµРґРµР»РёС‚СЊ РєРѕРЅС‚РµРєСЃС‚ Р°РІС‚РѕСЂРёР·Р°С†РёРё РёР· РјР°СЃСЃРёРІР°
     */
    protected function resolveAuthContext(?array $context): ?AuthorizationContext
    {
        if (!$context) {
            return null;
        }

        if (isset($context['project_id'])) {
            $organizationId = $context['organization_id'] ?? null;
            
            if (!$organizationId) {
                $project = \App\Models\Project::find($context['project_id']);
                if ($project) {
                    $organizationId = $project->organization_id;
                }
            }

            if ($organizationId) {
                return AuthorizationContext::getProjectContext(
                    $context['project_id'], 
                    $organizationId
                );
            }
        }

        if (isset($context['organization_id'])) {
            return AuthorizationContext::getOrganizationContext($context['organization_id']);
        }

        return AuthorizationContext::getSystemContext();
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РёРµСЂР°СЂС…РёСЋ РєРѕРЅС‚РµРєСЃС‚Р° (РѕС‚ С‚РµРєСѓС‰РµРіРѕ Рє РєРѕСЂРЅСЋ)
     */
    protected function getContextHierarchy(AuthorizationContext $context): Collection
    {
        return collect($context->getHierarchy());
    }

    /**
     * РћС†РµРЅРёС‚СЊ СѓСЃР»РѕРІРёСЏ СЂРѕР»Рё (ABAC)
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
     * РџРѕР»СѓС‡РёС‚СЊ СЃР»Р°РіРё СЂРѕР»РµР№ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РґР»СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё СЃРѕ СЃС‚Р°СЂРѕР№ СЃРёСЃС‚РµРјРѕР№
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
            // Р•СЃР»Рё С‚Р°Р±Р»РёС†С‹ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјС‹ РµС‰Рµ РЅРµ СЃРѕР·РґР°РЅС‹ - РІРѕР·РІСЂР°С‰Р°РµРј РїСѓСЃС‚РѕР№ РјР°СЃСЃРёРІ
            return [];
        }
    }
}
