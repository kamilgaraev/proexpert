<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\ValueObjects\ModulePermissionAliases;
use App\Services\Logging\LoggingService;
use App\Services\Monitoring\ApiQueryMetrics;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\Repository;
use Closure;

/**
 * Сервис для резолвинга прав (системных и модульных)
 */
class PermissionResolver
{
    private const CACHE_SCHEMA_VERSION = 'v4';

    protected RoleScanner $roleScanner;

    protected ModulePermissionChecker $moduleChecker;

    protected LoggingService $logging;

    private ?Repository $readCache = null;
    private bool $currentChecks = false;
    private ?OrganizationEntitlementService $currentEntitlementSource = null;

    public function __construct(
        RoleScanner $roleScanner,
        ModulePermissionChecker $moduleChecker,
        LoggingService $logging
    ) {
        $this->roleScanner = $roleScanner;
        $this->moduleChecker = $moduleChecker;
        $this->logging = $logging;
    }

    public function forCurrentChecks(): self
    {
        $scope = clone $this;
        $scope->currentChecks = true;
        $scope->readCache = null;
        return $scope;
    }

    public function withCurrentEntitlementSource(OrganizationEntitlementService $source): self
    {
        $scope = clone $this;
        $scope->currentEntitlementSource = $source;

        return $scope;
    }

    private function rememberCurrent(string $key, int $ttl, Closure $read): mixed
    {
        return $this->currentChecks ? $read() : Cache::remember($key, $ttl, $read);
    }

    public function forReadScope(Repository $readCache): self
    {
        $scope = clone $this;
        $scope->readCache = $readCache;

        return $scope;
    }

    private function rememberRead(string $key, Closure $read): mixed
    {
        return $this->readCache === null ? $read() : $this->readCache->remember($key, 300, $read);
    }

    /**
     * Проверить, есть ли у назначения роли указанное право
     */
    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        static $depthCounter = 0;
        $maxDepth = 50;

        if ($depthCounter >= $maxDepth) {
            $this->logging->security('permission.max_depth_exceeded', [
                'user_id' => $assignment->user_id,
                'permission' => $permission,
                'max_depth' => $maxDepth,
            ], 'error');

            return false;
        }

        $depthCounter++;

        try {
            $startTime = microtime(true);

            $cacheKey = $this->getVersionedCacheKey($assignment->user_id, $assignment->role_slug, $permission, $context);
            $cachedResult = $this->currentChecks ? null : Cache::get($cacheKey);

            if ($cachedResult !== null) {
                return $cachedResult;
            }

            $timeout = 5.0;
            if ((microtime(true) - $startTime) > $timeout) {
                $this->logging->security('permission.resolve.timeout', [
                    'user_id' => $assignment->user_id,
                    'permission' => $permission,
                    'timeout_seconds' => $timeout,
                ], 'error');

                return false;
            }

            $userAgent = request()->userAgent() ?? '';
            if (! str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('permission.resolve.start', [
                    'user_id' => $assignment->user_id,
                    'role_slug' => $assignment->role_slug,
                    'role_type' => $assignment->role_type,
                    'permission' => $permission,
                    'context' => $context,
                    'depth' => $depthCounter,
                ]);
            }

            $hasSystemPerm = ApiQueryMetrics::measureProcessingPhase('access_system_match',
                fn (): bool => $this->hasSystemPermission($assignment, $permission));

            if ($hasSystemPerm) {
                if (! $this->currentChecks) { Cache::put($cacheKey, true, 300); }

                if (! str_contains($userAgent, 'Prometheus')) {
                    $this->logging->security('permission.granted.system', [
                        'user_id' => $assignment->user_id,
                        'role_slug' => $assignment->role_slug,
                        'permission' => $permission,
                        'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    ]);
                }

                return true;
            }

            $hasModulePerm = ApiQueryMetrics::measureProcessingPhase('access_module_match',
                fn (): bool => $this->hasModulePermission($assignment, $permission, $context));

            if ($hasModulePerm) {
                if (! $this->currentChecks) { Cache::put($cacheKey, true, 300); }

                if (! str_contains($userAgent, 'Prometheus')) {
                    $this->logging->security('permission.granted.module', [
                        'user_id' => $assignment->user_id,
                        'role_slug' => $assignment->role_slug,
                        'permission' => $permission,
                        'context' => $context,
                        'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    ]);
                }

                return true;
            }

            if (! $this->currentChecks) { Cache::put($cacheKey, false, 300); }

            if (! str_contains($userAgent, 'Prometheus')) {
                $this->logging->security('permission.denied.complete', [
                    'user_id' => $assignment->user_id,
                    'role_slug' => $assignment->role_slug,
                    'role_type' => $assignment->role_type,
                    'permission' => $permission,
                    'context' => $context,
                    'checked_system' => true,
                    'checked_modules' => true,
                    'resolve_duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ], 'info');
            }

            return false;
        } finally {
            $depthCounter--;
        }
    }

    /**
     * Проверить системное право
     */
    public function hasSystemPermission(UserRoleAssignment $assignment, string $permission): bool
    {
        $systemPermissions = $this->getSystemPermissions($assignment);
        $permissionVariants = $this->expandSystemPermissionVariants($permission);

        // Проверяем точное совпадение
        foreach ($permissionVariants as $permissionVariant) {
            if (in_array($permissionVariant, $systemPermissions, true)) {
                return true;
            }
        }

        // Проверяем wildcard права
        if (in_array('*', $systemPermissions)) {
            return true;
        }

        // Проверяем права с точками (например: users.* для users.view)
        foreach ($systemPermissions as $rolePermission) {
            foreach ($permissionVariants as $permissionVariant) {
                if ($this->matchesWildcard($permissionVariant, $rolePermission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверить модульное право
     */
    public function hasModulePermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        Log::debug('permission.module.check.start', [
            'permission' => $permission,
            'role_slug' => $assignment->role_slug,
            'role_type' => $assignment->role_type,
        ]);

        $organizationId = $this->extractOrganizationId($assignment, $context);

        if (! $organizationId) {
            $this->logging->technical('permission.module.denied.no_org', [
                'permission' => $permission,
            ]);

            return false;
        }

        $parts = explode('.', $permission, 2);
        if (count($parts) !== 2) {
            $this->logging->technical('permission.module.denied.invalid_format', [
                'permission' => $permission,
            ]);

            return false;
        }

        [$module, $action] = $this->normalizeAdminModulePermissionParts($parts[0], $parts[1]);

        $modulesToCheck = $this->expandModuleVariants($module);

        $this->logging->technical('permission.module.parsed', [
            'module' => $module,
            'action' => $action,
            'organization_id' => $organizationId,
            'modules_to_check' => $modulesToCheck,
        ]);

        $modulePermissions = $this->getModulePermissions($assignment);

        // Проверяем каждый модуль из списка
        foreach ($modulesToCheck as $moduleToCheck) {
            $cacheKey = 'module_active_'.self::CACHE_SCHEMA_VERSION."_{$moduleToCheck}_{$organizationId}";
            $isActive = $this->rememberRead($cacheKey, fn () => $this->rememberCurrent($cacheKey, 300, function () use ($moduleToCheck, $organizationId) {
                if ($this->currentChecks) {
                    $activeModules = $this->rememberRead('current_active_modules_'.$organizationId,
                        fn (): array => ($this->currentEntitlementSource ?? app(OrganizationEntitlementService::class))
                            ->getEffectiveModules($organizationId)->pluck('slug')->all());

                    return in_array($moduleToCheck, $activeModules, true);
                }
                if ($this->readCache !== null) {
                    $activeModules = $this->rememberRead(
                        'active_modules_'.$organizationId,
                        fn () => $this->moduleChecker->getActiveModules($organizationId),
                    );

                    return in_array($moduleToCheck, $activeModules, true);
                }

                return $this->moduleChecker->isModuleActive($moduleToCheck, $organizationId);
            }));

            Log::debug('permission.module.active_check', [
                'module' => $moduleToCheck,
                'is_active' => $isActive,
            ]);

            if (! $isActive) {
                continue;
            }

            if ($this->checkModulePermission($modulePermissions, $moduleToCheck, $module, $action, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить все системные права роли
     */
    protected function normalizeAdminModulePermissionParts(string $module, string $action): array
    {
        if ($module !== 'admin') {
            return [$module, $action];
        }

        $actionParts = explode('.', $action, 2);
        if (count($actionParts) !== 2) {
            return [$module, $action];
        }

        [$modulePrefix, $moduleAction] = $actionParts;

        $legacyAdminModulePrefixes = [
            'ai_assistant',
            'contracts',
            'materials',
            'projects',
            'reports',
            'time_tracking',
            'users',
        ];

        if (in_array($modulePrefix, $legacyAdminModulePrefixes, true)) {
            return [$modulePrefix, $moduleAction];
        }

        return [$module, $action];
    }

    protected function expandSystemPermissionVariants(string $permission): array
    {
        $variants = [$permission];

        $legacyAdminSystemAliases = [
            'admin.dashboard.view' => ['dashboard.view'],
            'admin.users.view' => ['users.view', 'users.manage', 'users.manage_admin'],
            'admin.users.create' => ['users.create', 'users.manage', 'users.manage_admin'],
            'admin.users.edit' => ['users.edit', 'users.manage', 'users.manage_admin'],
            'admin.users.block' => ['users.block', 'users.edit', 'users.manage', 'users.manage_admin'],
        ];

        foreach ($legacyAdminSystemAliases[$permission] ?? [] as $alias) {
            $variants[] = $alias;
        }

        return array_values(array_unique($variants));
    }

    public function getSystemPermissions(UserRoleAssignment $assignment): array
    {
        $organizationId = $this->extractOrganizationId($assignment);
        $roleRevision = $this->rememberRead('authorization_roles_revision', fn () => Cache::get('authorization_roles_revision', 0));
        $cacheKey = 'system_perms_'.self::CACHE_SCHEMA_VERSION."_r{$roleRevision}_{$assignment->role_type}_{$assignment->role_slug}_".($organizationId ?? 'global');

        return $this->rememberRead($cacheKey, fn () => $this->rememberCurrent($cacheKey, 600, function () use ($assignment, $organizationId) {
            $perms = [];
            $interfaceAccess = [];

            // 1. Пробуем получить как системную роль (из файлов)
            $perms = $this->roleScanner->getSystemPermissions($assignment->role_slug);
            $interfaceAccess = $this->roleScanner->getInterfaceAccess($assignment->role_slug);

            // 2. Если в файлах пусто (роль кастомная) — ищем в БД
            if (empty($perms) && (empty($interfaceAccess) || count($interfaceAccess) === 0)) {
                $customRole = $this->getCustomRole($assignment->role_slug, $organizationId);
                $perms = $customRole ? ($customRole->system_permissions ?? []) : [];
                $interfaceAccess = $customRole ? ($customRole->interface_access ?? []) : [];
            }

            return RolePermissionNormalizer::normalizeSystemPermissions($perms, $interfaceAccess);
        }));
    }

    /**
     * Получить все модульные права роли
     */
    public function getModulePermissions(UserRoleAssignment $assignment): array
    {
        $organizationId = $this->extractOrganizationId($assignment);
        $roleRevision = $this->rememberRead('authorization_roles_revision', fn () => Cache::get('authorization_roles_revision', 0));
        $cacheKey = 'module_perms_'.self::CACHE_SCHEMA_VERSION."_r{$roleRevision}_{$assignment->role_type}_{$assignment->role_slug}_".($organizationId ?? 'global');

        return $this->rememberRead($cacheKey, fn () => $this->rememberCurrent($cacheKey, 600, function () use ($assignment, $organizationId) {
            // 1. Пробуем из файлов
            $perms = $this->roleScanner->getModulePermissions($assignment->role_slug);

            // 2. Если в файлах пусто — ищем в БД
            if (empty($perms)) {
                $customRole = $this->getCustomRole($assignment->role_slug, $organizationId);

                return RolePermissionNormalizer::normalizeModulePermissions(
                    $customRole ? ($customRole->module_permissions ?? []) : []
                );
            }

            return RolePermissionNormalizer::normalizeModulePermissions($perms);
        }));
    }

    /**
     * Получить все права системной роли
     */
    public function getSystemRolePermissions(string $roleSlug): array
    {
        $systemPermissions = $this->roleScanner->getSystemPermissions($roleSlug);
        $modulePermissions = RolePermissionNormalizer::normalizeModulePermissions(
            $this->roleScanner->getModulePermissions($roleSlug)
        );

        $allPermissions = $systemPermissions;

        // Преобразуем модульные права в полные права
        foreach ($modulePermissions as $module => $permissions) {
            foreach ($permissions as $permission) {
                if ($permission === '*') {
                    $allPermissions[] = "$module.*";
                } else {
                    $allPermissions[] = "$module.$permission";
                }
            }
        }

        return array_unique($allPermissions);
    }

    /**
     * Получить все права кастомной роли
     */
    public function getCustomRolePermissions(string $roleSlug, ?int $organizationId = null): array
    {
        $customRole = $this->getCustomRole($roleSlug, $organizationId);

        if (! $customRole) {
            return [];
        }

        $allPermissions = $customRole->system_permissions ?? [];

        // Преобразуем модульные права в полные права
        $modulePermissions = RolePermissionNormalizer::normalizeModulePermissions($customRole->module_permissions ?? []);

        foreach ($modulePermissions as $module => $permissions) {
            foreach ($permissions as $permission) {
                if ($permission === '*') {
                    $allPermissions[] = "$module.*";
                } else {
                    $allPermissions[] = "$module.$permission";
                }
            }
        }

        return array_unique($allPermissions);
    }

    /**
     * Проверить модульное право
     */
    protected function checkModulePermission(
        array $modulePermissions,
        string $module,
        string $requestedModule,
        string $action,
        string $requestedPermission
    ): bool {
        if ($requestedModule === 'finance' && !array_key_exists('finance', $modulePermissions)) {
            $canonicalPermissions = $modulePermissions['payments'] ?? [];
            $modulePermissions['finance'] = is_array($canonicalPermissions)
                ? array_values(array_filter($canonicalPermissions, static fn (mixed $permission): bool => is_string($permission) && str_starts_with($permission, 'finance.')))
                : [];
        }

        $modulePermissionKey = $this->resolveModulePermissionKey($modulePermissions, $module, $requestedModule);

        $this->logging->technical('permission.module.check_permissions', [
            'module' => $module,
            'module_permission_key' => $modulePermissionKey,
            'action' => $action,
            'available_modules' => array_keys($modulePermissions),
            'module_exists' => $modulePermissionKey !== null,
            'permissions_for_module' => $modulePermissionKey !== null
                ? ($modulePermissions[$modulePermissionKey] ?? 'NOT_FOUND')
                : 'NOT_FOUND',
        ]);

        if ($modulePermissionKey === null) {
            $this->logging->technical('permission.module.denied.module_not_found', [
                'module' => $module,
            ]);

            return false;
        }

        $permissions = $modulePermissions[$modulePermissionKey];
        $permissionVariants = $this->buildPermissionVariants($requestedModule, $module, $action);

        // Проверяем точное совпадение
        if (in_array($action, $permissions)) {
            $this->logging->technical('permission.module.granted.exact_match', [
                'module' => $module,
                'action' => $action,
            ]);

            return true;
        }

        // Проверяем wildcard
        if (in_array('*', $permissions)) {
            $this->logging->technical('permission.module.granted.wildcard', [
                'module' => $module,
                'action' => $action,
            ]);

            return true;
        }

        // Проверяем wildcard с префиксом (например: create_* для create_project)
        if (in_array($requestedPermission, $permissions, true) || count(array_intersect($permissionVariants, $permissions)) > 0) {
            $this->logging->technical('permission.module.granted.qualified_match', [
                'module' => $module,
                'action' => $action,
                'requested_permission' => $requestedPermission,
            ]);

            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->matchesWildcard($action, $permission)) {
                $this->logging->technical('permission.module.granted.pattern_match', [
                    'module' => $module,
                    'action' => $action,
                    'pattern' => $permission,
                ]);

                return true;
            }

            foreach ($permissionVariants as $permissionVariant) {
                if ($this->matchesWildcard($permissionVariant, $permission)) {
                    $this->logging->technical('permission.module.granted.qualified_pattern_match', [
                        'module' => $module,
                        'action' => $action,
                        'pattern' => $permission,
                        'requested_permission' => $permissionVariant,
                    ]);

                    return true;
                }
            }
        }

        Log::debug('permission.module.denied.no_match', [
            'module' => $module,
            'action' => $action,
        ]);

        return false;
    }

    /**
     * Извлечь ID организации из назначения или контекста
     */
    protected function resolveModulePermissionKey(array $modulePermissions, string $module, string $requestedModule): ?string
    {
        if ($requestedModule === 'finance') {
            return isset($modulePermissions['finance']) && is_array($modulePermissions['finance']) ? 'finance' : null;
        }

        foreach ($this->modulePermissionKeyVariants($module, $requestedModule) as $moduleKey) {
            if (isset($modulePermissions[$moduleKey]) && is_array($modulePermissions[$moduleKey])) {
                return $moduleKey;
            }
        }

        return null;
    }

    protected function modulePermissionKeyVariants(string $module, string $requestedModule): array
    {
        $variants = [];

        foreach ([$module, $requestedModule] as $moduleCandidate) {
            foreach ($this->expandModuleVariants($moduleCandidate) as $variant) {
                $variants[] = $variant;
                $variants[] = str_replace('-', '_', $variant);
                $variants[] = str_replace('_', '-', $variant);
            }
        }

        return array_values(array_unique($variants));
    }

    public function extractOrganizationId(UserRoleAssignment $assignment, ?array $context = null): ?int
    {
        // Сначала пробуем из контекста
        if ($context && isset($context['organization_id'])) {
            return $context['organization_id'];
        }

        // Затем из контекста назначения (с eager loading)
        $authContext = $assignment->relationLoaded('context')
            ? $assignment->context
            : $assignment->load('context.parentContext')->context;

        if ($authContext && $authContext->type === 'organization') {
            return $authContext->resource_id;
        }

        if ($authContext && $authContext->type === 'project') {
            // Нужно получить организацию проекта через родительский контекст
            $parentContext = $authContext->relationLoaded('parentContext')
                ? $authContext->parentContext
                : $authContext->load('parentContext')->parentContext;
            if ($parentContext && $parentContext->type === 'organization') {
                return $parentContext->resource_id;
            }
        }

        return null;
    }

    /**
     * Получить кастомную роль с кешированием
     */
    protected function getCustomRole(string $roleSlug, ?int $organizationId = null): ?OrganizationCustomRole
    {
        $cacheKey = "custom_role_{$roleSlug}_".($organizationId ?? 'global');

        return $this->rememberCurrent($cacheKey, 300, function () use ($roleSlug, $organizationId) {
            $query = OrganizationCustomRole::where('slug', $roleSlug);
            if ($this->currentChecks) { $query->where('is_active', true); }

            if ($organizationId) {
                $query->where('organization_id', $organizationId);
            }

            return $query->first();
        });
    }

    /**
     * Очистить кеш разрешений для пользователя
     */
    public function clearUserPermissionCache(int $userId): void
    {
        // Простое решение - используем тег кеша или версионирование
        // Для более сложной очистки можно использовать версионирование кеша
        $currentVersion = Cache::get("user_permission_version_{$userId}", 0);
        Cache::put("user_permission_version_{$userId}", $currentVersion + 1, 3600);
    }

    public function clearRolePermissionCache(
        string $roleSlug,
        string $roleType = UserRoleAssignment::TYPE_CUSTOM,
        ?int $organizationId = null,
        iterable $userIds = []
    ): void {
        $scopeKeys = ['global'];
        $roleRevision = Cache::get('authorization_roles_revision', 0);

        if ($organizationId !== null) {
            $scopeKeys[] = (string) $organizationId;
        }

        foreach (array_unique($scopeKeys) as $scopeKey) {
            Cache::forget("custom_role_{$roleSlug}_{$scopeKey}");
            Cache::forget("system_perms_{$roleType}_{$roleSlug}_{$scopeKey}");
            Cache::forget("module_perms_{$roleType}_{$roleSlug}_{$scopeKey}");
            Cache::forget('system_perms_'.self::CACHE_SCHEMA_VERSION."_r{$roleRevision}_{$roleType}_{$roleSlug}_{$scopeKey}");
            Cache::forget('module_perms_'.self::CACHE_SCHEMA_VERSION."_r{$roleRevision}_{$roleType}_{$roleSlug}_{$scopeKey}");
        }

        foreach ($userIds as $userId) {
            if (is_numeric($userId)) {
                $this->clearUserPermissionCache((int) $userId);
            }
        }
    }

    /**
     * Очистить весь кеш разрешений (для emergency случаев)
     */
    public function clearAllPermissionCache(): void
    {
        $currentVersion = Cache::get('permission_global_version', 0);
        Cache::put('permission_global_version', $currentVersion + 1, 3600);
    }

    /**
     * Получить ключ кеша с версионированием
     */
    protected function getVersionedCacheKey(int $userId, string $roleSlug, string $permission, ?array $context = null): string
    {
        $userVersion = $this->rememberRead("user_permission_version_{$userId}", fn () => Cache::get("user_permission_version_{$userId}", 0));
        $globalVersion = $this->rememberRead('permission_global_version', fn () => Cache::get('permission_global_version', 0));
        $roleRevision = $this->rememberRead('authorization_roles_revision', fn () => Cache::get('authorization_roles_revision', 0));

        $baseKey = 'permission_'.self::CACHE_SCHEMA_VERSION."_r{$roleRevision}_{$userId}_{$roleSlug}_{$permission}_".md5(json_encode($context));

        return "{$baseKey}_v{$userVersion}_{$globalVersion}";
    }

    /**
     * Проверить соответствие wildcard шаблону
     *
     * @param  string  $permission  Проверяемое право (например: admin.access)
     * @param  string  $pattern  Wildcard шаблон (например: admin.*, *.view, admin.*.edit)
     * @return bool
     */
    protected function expandModuleVariants(string $module): array
    {
        return ModulePermissionAliases::variants($module);
    }

    protected function buildPermissionVariants(string $requestedModule, string $resolvedModule, string $action): array
    {
        $moduleVariants = array_unique([
            $requestedModule,
            $resolvedModule,
            str_replace('_', '-', $requestedModule),
            str_replace('-', '_', $requestedModule),
            str_replace('_', '-', $resolvedModule),
            str_replace('-', '_', $resolvedModule),
        ]);

        if (in_array($requestedModule, ['estimates', 'budget-estimates', 'budget_estimates'], true)) {
            $moduleVariants = array_merge($moduleVariants, ['estimates', 'budget-estimates', 'budget_estimates']);
        }

        $variants = [];

        foreach ($moduleVariants as $moduleVariant) {
            $variants[] = "{$moduleVariant}.{$action}";
        }

        return array_values(array_unique($variants));
    }

    protected function matchesWildcard(string $permission, string $pattern): bool
    {
        // Если нет wildcard, то точное сравнение
        if (strpos($pattern, '*') === false) {
            return $permission === $pattern;
        }

        // Полный wildcard
        if ($pattern === '*') {
            return true;
        }

        // ПРАВИЛЬНЫЙ АЛГОРИТМ: сначала * → плейсхолдер, потом экранируем, потом плейсхолдер → .*
        // 1. Заменяем * на уникальный плейсхолдер
        $placeholder = '___WILDCARD_PLACEHOLDER___';
        $withPlaceholder = str_replace('*', $placeholder, $pattern);

        // 2. Экранируем все regex спецсимволы
        $escaped = preg_quote($withPlaceholder, '/');

        // 3. Заменяем плейсхолдер на .*
        $regexPattern = '/^'.str_replace($placeholder, '.*', $escaped).'$/';

        $result = preg_match($regexPattern, $permission) === 1;

        return $result;
    }
}
