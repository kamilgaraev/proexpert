<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Services\Logging\LoggingService;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Support\Collection;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Closure;

use function trans_message;

/**
 * Главный сервис авторизации
 */
class AuthorizationService
{
    protected RoleScanner $roleScanner;
    protected PermissionResolver $permissionResolver;
    protected LoggingService $logging;

    private ?Repository $readCache = null;
    private bool $currentChecks = false;
    private ?Closure $currentDecisionObserver = null;
    private array $trustedDecisionInputs = [];

    public function __construct(
        RoleScanner $roleScanner,
        PermissionResolver $permissionResolver,
        LoggingService $logging
    ) {
        $this->roleScanner = $roleScanner;
        $this->permissionResolver = $permissionResolver;
        $this->logging = $logging;
    }

    public function forCurrentChecks(bool $memoizeReads = false): self
    {
        $scope = clone $this;
        $scope->currentChecks = true;
        $scope->readCache = $memoizeReads ? new Repository(new ArrayStore) : null;
        $scope->permissionResolver = $this->permissionResolver->forCurrentChecks();
        if ($scope->readCache !== null) {
            $scope->permissionResolver = $scope->permissionResolver->forReadScope($scope->readCache);
        }
        return $scope;
    }

    public function canCurrent(User $user, string $permission, ?array $context = null): bool
    {
        $request = app()->bound('request') ? app('request') : null;
        $checkpoint = $request instanceof Request ? ApiQueryMetrics::processingCheckpoint($request) : null;
        try {
            if ($this->trustedDecisionInputs !== []) { $context = array_replace($this->trustedDecisionInputs, $context ?? []); }
            $scope = $this->currentChecks && $this->readCache !== null ? $this : $this->forCurrentChecks();
            $result = $scope->rememberRead('current_permission:'.$user->id.':'.$permission.':'.hash('sha256', serialize($context)),
                fn (): bool => ApiQueryMetrics::measureProcessingPhase('current_access_evaluate',
                    fn (): bool => $scope->checkPermission($user, $permission, $context)));
            $this->currentDecisionObserver?->__invoke($user, $permission, $context, $result);

            return $result;
        } finally {
            if ($request instanceof Request && $checkpoint !== null) {
                ApiQueryMetrics::recordProcessingPhase($request, 'current_access_check', $checkpoint['started_at'], $checkpoint);
            }
        }
    }

    public function captureCurrentDecisions(array $trustedInputs, callable $operation): array
    {
        $previous = [$this->currentDecisionObserver, $this->trustedDecisionInputs];
        $decisions = [];
        $this->trustedDecisionInputs = $trustedInputs;
        $this->currentDecisionObserver = static function (User $user, string $permission, ?array $context, bool $allowed) use (&$decisions): void {
            $key = hash('sha256', serialize([(int) $user->id, $permission, $context]));
            $decisions[$key] = ['actor_id' => (int) $user->id, 'permission' => $permission, 'context' => $context, 'allowed' => $allowed];
        };
        try {
            return ['value' => $operation(), 'decisions' => array_values($decisions)];
        } finally {
            [$this->currentDecisionObserver, $this->trustedDecisionInputs] = $previous;
        }
    }

    private function rememberArray(string $key, int $ttl, Closure $read): mixed
    {
        return $this->currentChecks ? $this->rememberRead($key, $read) : Cache::driver('array')->remember($key, $ttl, $read);
    }

    public function forReadScope(): self
    {
        $scope = clone $this;
        $scope->readCache = new Repository(new ArrayStore);
        $scope->permissionResolver = $this->permissionResolver->forReadScope($scope->readCache);

        return $scope;
    }

    private function rememberRead(string $key, Closure $read): mixed
    {
        return $this->readCache === null ? $read() : $this->readCache->remember($key, 300, $read);
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
            
            $result = $this->rememberArray($cacheKey, 300, function () use ($user, $permission, $context) {
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
                    ], 'warning');
                }
            }

            return $result;
        } finally {
            unset($callStack[$callKey]);
        }
    }

    public function canInContext(
        User $user,
        string $permission,
        AuthorizationDecisionContext $context,
    ): bool {
        return $this->evaluatePermission(
            $user,
            $permission,
            $context->toAuthorizationArray(),
            '',
        );
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
        
        return $this->rememberArray($cacheKey, 300, function () use ($user, $context) {
            $query = $user->roleAssignments()
                ->active()
                ->with('customRole');
            $hierarchy = null;
            
            if ($context) {
                $hierarchy = $this->getContextHierarchy($context);
                $contextIds = $hierarchy->pluck('id');
                
                // Для проектных контекстов также добавляем все проектные контексты организации
                // (роли могут быть назначены в разных проектных контекстах)
                if ($context->type === AuthorizationContext::TYPE_PROJECT && $context->parent_context_id) {
                    try {
                        $orgContext = $hierarchy->firstWhere('id', $context->parent_context_id);
                        if ($orgContext) {
                            $projectContexts = $this->rememberRead('sibling_project_contexts_'.$orgContext->id,
                                fn (): Collection => AuthorizationContext::where('parent_context_id', $orgContext->id)
                                ->where('type', AuthorizationContext::TYPE_PROJECT)
                                ->pluck('id'));
                            $contextIds = $contextIds->merge($projectContexts)->unique();
                        }
                    } catch (\Exception $e) {
                        // Игнорируем ошибки при поиске контекстов - используем только иерархию
                    }
                }
                
                $query->whereIn('context_id', $contextIds);
            }
            
            $knownHierarchy = $this->currentChecks && $this->readCache !== null
                && $context?->type === AuthorizationContext::TYPE_ORGANIZATION
                && $this->readCache->get($this->authContextReadKey(['organization_id' => (int) $context->resource_id])) === $context
                && $hierarchy?->firstWhere('id', $context->id) === $context ? $hierarchy : null;

            return $this->loadRoleContexts(
                $query->get(),
                $query->getModel()->getConnectionName(),
                $knownHierarchy,
            );
        });
    }

    private function loadRoleContexts(Collection $roles, ?string $connection, ?Collection $hierarchy = null): Collection
    {
        $ids = $roles->pluck('context_id')->filter(static fn ($id): bool => $id !== null)->unique()->values()->all();
        $contexts = null;
        if ($hierarchy !== null) {
            $knownContexts = $hierarchy->keyBy('id');
            $connectionName = (new AuthorizationContext)->setConnection($connection)->getConnection()->getName();
            $contexts = collect();
            foreach ($ids as $id) {
                $context = $knownContexts->get($id);
                $parent = $context instanceof AuthorizationContext ? $knownContexts->get($context->parent_context_id) : null;
                if (! $context instanceof AuthorizationContext || $context->getConnection()->getName() !== $connectionName
                    || ($context->parent_context_id !== null && (! $parent instanceof AuthorizationContext || $parent->getConnection()->getName() !== $connectionName))) {
                    $contexts = null;
                    break;
                }
                $contexts->put($id, clone $context);
                if ($parent instanceof AuthorizationContext) {
                    $contexts->put($parent->id, clone $parent);
                }
            }
        }
        $contexts ??= $ids === [] ? collect() : AuthorizationContext::on($connection)
            ->whereIn('id', $ids)
            ->orWhereIn('id', AuthorizationContext::on($connection)->select('parent_context_id')->whereIn('id', $ids))
            ->get()->keyBy('id');

        foreach ($ids as $id) {
            $context = $contexts->get($id);
            if ($context instanceof AuthorizationContext) {
                $parent = $contexts->get($context->parent_context_id);
                $parent = $parent instanceof AuthorizationContext ? clone $parent : null;
                $parent?->unsetRelation('parentContext');
                $context->setRelation('parentContext', $parent);
            }
        }
        foreach ($roles as $role) {
            $customRole = $role->getRelation('customRole');
            $role->unsetRelation('customRole');
            $role->setRelation('context', $contexts->get($role->context_id));
            $role->setRelation('customRole', $customRole);
        }

        return $roles;
    }

    /**
     * Получить все права пользователя (плоский список)
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
        
        return array_values(array_unique($permissions));
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
                    'role_slug' => $roleSlug,
                    'role_type' => $roleType,
                    'assigned_by' => $assignedBy?->id,
                    'context_type' => $context->type,
                    'context_id' => $context->id,
                    'error' => 'System role does not exist'
                ], 'error');
                throw new \InvalidArgumentException(trans_message('permissions.system_role_missing', ['role' => $roleSlug]));
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
                    'role_slug' => $roleSlug,
                    'role_type' => $roleType,
                    'assigned_by' => $assignedBy?->id,
                    'context_type' => $context->type,
                    'context_id' => $context->id,
                    'error' => 'Custom role does not exist'
                ], 'error');
                throw new \InvalidArgumentException(trans_message('permissions.custom_role_missing', ['role' => $roleSlug]));
            }
        }

        $assignment = UserRoleAssignment::assignRole($user, $roleSlug, $context, $roleType, $assignedBy, $expiresAt);

        // AUDIT: Назначение роли - критически важное событие
        $this->logging->audit('auth.role.assigned', [
            'target_user_id' => $user->id,
            'role_slug' => $roleSlug,
            'role_type' => $roleType,
            'assigned_by' => $assignedBy?->id,
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
    public function revokeRole(User $user, string $roleSlug, AuthorizationContext $context, ?User $revokedBy = null): bool
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
                    'role_slug' => $roleSlug,
                    'role_type' => $assignment->role_type,
                    'revoked_by' => $revokedBy?->id,
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
            $orgId = $this->permissionResolver->extractOrganizationId($assignment);
            $interfaceAccess = $this->getRoleInterfaceAccess($assignment->role_slug, $assignment->role_type, $orgId);
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
        return $this->evaluatePermission(
            $user,
            $permission,
            $context,
            $this->requestUserAgent(),
        );
    }

    private function evaluatePermission(
        User $user,
        string $permission,
        ?array $context,
        string $userAgent,
    ): bool
    {
        // Если контекст не передан, но есть модульное право - определяем контекст организации автоматически
        if (!$context && $user->current_organization_id) {
            // Проверяем, является ли право модульным (содержит точку)
            if (strpos($permission, '.') !== false) {
                $parts = explode('.', $permission, 2);
                $module = $parts[0];
                
                // Для модульных прав используем контекст организации
                $context = [
                    'context_type' => 'organization',
                    'organization_id' => $user->current_organization_id
                ];
            }
        }
        
        $authContext = $this->rememberRead(
            $this->authContextReadKey($context),
            fn () => $this->resolveAuthContext($context),
        );
        if ($this->currentChecks && $context !== null && $authContext === null) { return false; }
        $roles = $this->getUserRoles($user, $authContext);
        if (($context['strict_project_scope'] ?? false) && $authContext?->type === AuthorizationContext::TYPE_PROJECT) {
            $contextIds = $this->getContextHierarchy($authContext)->pluck('id')->all();
            $roles = $roles->filter(static fn (UserRoleAssignment $assignment): bool => in_array($assignment->context_id, $contextIds, true));
        }
        
        if ($roles->isEmpty()) {
            if (!str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('auth.no_roles_found', [
                    'user_id' => $user->id,
                    'permission_requested' => $permission,
                    'context' => $context
                ], 'info');
            }
            return false;
        }

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

        // Проверка родительских организаций для организационных контекстов
        if ($authContext && $authContext->type === AuthorizationContext::TYPE_ORGANIZATION) {
            $cacheKey = "org_parent_{$authContext->resource_id}";
            $orgData = $this->rememberArray($cacheKey, 300, function () use ($authContext) {
                return \App\Models\Organization::where('id', $authContext->resource_id)
                    ->select('id', 'parent_organization_id')
                    ->first();
            });

            if ($orgData && $orgData->parent_organization_id) {
                $parentContextCacheKey = "org_context_{$orgData->parent_organization_id}";
                $parentContext = $this->rememberArray($parentContextCacheKey, 300, function () use ($orgData) {
                    return $this->currentChecks
                        ? AuthorizationContext::query()->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $orgData->parent_organization_id)->first()
                        : AuthorizationContext::getOrganizationContext($orgData->parent_organization_id);
                });

                if ($parentContext && $this->checkPermissionInContext($user, $permission, $parentContext)) {
                    return true;
                }
            }
        }
        
        // Для проектных контекстов также проверяем контекст организации (роли могут быть назначены там)
        if ($authContext && $authContext->type === AuthorizationContext::TYPE_PROJECT && $authContext->parent_context_id) {
            try {
                $orgContext = $this->rememberRead(
                    'parent_context_'.$authContext->parent_context_id,
                    fn () => AuthorizationContext::find($authContext->parent_context_id),
                );
                if ($orgContext && $this->checkPermissionInContext($user, $permission, $orgContext)) {
                    return true;
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки при поиске контекста организации
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
     * Получить все права роли
     */
    protected function getRolePermissions(string $roleSlug, string $roleType, ?int $organizationId = null): array
    {
        // 1. Пытаемся получить системные права из файлов (если тип system или если не уверены)
        $permissions = [];
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM || empty($roleType)) {
            $permissions = $this->roleScanner->getSystemPermissions($roleSlug);
        }

        // 2. Если системных прав нет, ищем в кастомных ролях в БД
        if (empty($permissions)) {
            return $this->permissionResolver->getCustomRolePermissions($roleSlug, $organizationId);
        }

        return $permissions;
    }

    /**
     * Получить доступ к интерфейсам для роли
     */
    protected function getRoleInterfaceAccess(string $roleSlug, string $roleType, ?int $organizationId = null): array
    {
        // 1. Пытаемся получить доступ из системных файлов
        $access = [];
        if ($roleType === UserRoleAssignment::TYPE_SYSTEM || empty($roleType)) {
            $access = $this->roleScanner->getInterfaceAccess($roleSlug);
        }

        // 2. Если в файлах ничего не найдено, ищем в кастомных ролях (БД)
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
     * Определить контекст авторизации из массива
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
                if ($this->currentChecks) {
                    return AuthorizationContext::query()->where('type', AuthorizationContext::TYPE_PROJECT)->where('resource_id', $context['project_id'])
                        ->whereHas('parentContext', static fn ($parent) => $parent->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $organizationId))->first();
                }
                return AuthorizationContext::getProjectContext(
                    $context['project_id'], 
                    $organizationId
                );
            }
        }

        if (isset($context['organization_id'])) {
            return $this->currentChecks
                ? AuthorizationContext::query()->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $context['organization_id'])->first()
                : AuthorizationContext::getOrganizationContext($context['organization_id']);
        }

        return AuthorizationContext::getSystemContext();
    }

    private function authContextReadKey(?array $context): string
    {
        $lookup = ! $context ? ['empty'] : (isset($context['project_id'])
            ? ['project', $context['project_id'], $context['organization_id'] ?? null]
            : (isset($context['organization_id']) ? ['organization', $context['organization_id']] : ['system']));

        return 'auth_context_'.hash('sha256', serialize($lookup));
    }

    /**
     * Получить иерархию контекста (от текущего к корню)
     */
    protected function getContextHierarchy(AuthorizationContext $context): Collection
    {
        return $this->rememberRead('context_hierarchy_'.$context->id, fn () => collect($context->getHierarchy()));
    }

    /**
     * Оценить условия роли (ABAC)
     */
    protected function evaluateConditions(UserRoleAssignment $assignment, array $context): bool
    {
        $conditions = $this->rememberRead(
            'role_conditions_'.$assignment->id,
            fn () => $assignment->conditions()->active()->get(),
        );
        
        foreach ($conditions as $condition) {
            if (!$condition->evaluate($context)) {
                return false;
            }
        }
        
        return true;
    }

    private function requestUserAgent(): string
    {
        $container = Container::getInstance();
        if (!$container->bound('request')) {
            return '';
        }

        $request = $container->make('request');

        return $request instanceof Request ? ($request->userAgent() ?? '') : '';
    }

    /**
     * Получить слаги ролей пользователя для совместимости со старой системой
     */
    public function getUserRoleSlugs(User $user, ?array $context = null): array
    {
        try {
            if ($this->currentChecks && isset($context['organization_id']) && ! isset($context['project_id'])) {
                $authContext = $this->rememberRead(
                    $this->authContextReadKey($context),
                    fn () => $this->resolveAuthContext($context),
                );
                if ($authContext === null) {
                    return [];
                }

                return $this->rememberRead(
                    'current_role_slugs_'.$user->id.':'.$authContext->id,
                    fn (): array => $this->getUserRoles($user, $authContext)->pluck('role_slug')->toArray(),
                );
            }
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
