<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

class AssistantAccessContextResolver
{
    private const MUTATION_SUFFIXES = [
        '.create',
        '.edit',
        '.delete',
        '.manage',
        '.approve',
        '.reject',
        '.send',
        '.change_status',
        '.update',
        '.execute',
        '.assign',
        '.block',
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function resolve(User $user, int $organizationId): array
    {
        $flatPermissions = array_values(array_unique(array_filter(
            $user->getPermissions(),
            static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== ''
        )));

        $structuredPermissions = $this->authorizationService->getUserPermissionsStructured($user);
        $normalizedStructuredPermissions = is_array($structuredPermissions) ? $structuredPermissions : [];
        $isReadOnly = !$this->hasMutationPermission($flatPermissions, $normalizedStructuredPermissions);

        return [
            'organization_id' => $organizationId,
            'can_use_assistant' => $user->belongsToOrganization($organizationId),
            'permissions_flat' => $flatPermissions,
            'permissions_structured' => $normalizedStructuredPermissions,
            'available_modules' => $this->resolveModules($flatPermissions, $normalizedStructuredPermissions),
            'permission_count' => count($flatPermissions),
            'is_read_only' => $isReadOnly,
            'allowed_action_types' => $isReadOnly
                ? ['summary', 'find', 'analyze', 'navigate']
                : ['summary', 'find', 'analyze', 'navigate', 'wizard', 'act'],
        ];
    }

    public function hasPermission(array $accessContext, string $permission): bool
    {
        if ($permission === '') {
            return false;
        }

        $permissions = is_array($accessContext['permissions_flat'] ?? null)
            ? $accessContext['permissions_flat']
            : [];
        $structuredPermissions = is_array($accessContext['permissions_structured'] ?? null)
            ? $accessContext['permissions_structured']
            : [];

        foreach ($this->buildPermissionVariants($permission) as $permissionVariant) {
            if ($this->hasFlatPermission($permissions, $permissionVariant)) {
                return true;
            }

            if ($this->hasStructuredPermission($structuredPermissions, $permissionVariant)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $accessContext, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (is_string($permission) && $this->hasPermission($accessContext, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function hasFlatPermission(array $permissions, string $permission): bool
    {
        foreach ($permissions as $grantedPermission) {
            if (!is_string($grantedPermission) || $grantedPermission === '') {
                continue;
            }

            if ($grantedPermission === $permission || $grantedPermission === '*') {
                return true;
            }

            if (str_ends_with($grantedPermission, '.*')) {
                $prefix = substr($grantedPermission, 0, -1);
                if ($prefix !== false && str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasStructuredPermission(array $structuredPermissions, string $permission): bool
    {
        $systemPermissions = is_array($structuredPermissions['system'] ?? null)
            ? $structuredPermissions['system']
            : [];

        if ($this->hasFlatPermission($systemPermissions, $permission)) {
            return true;
        }

        [$module, $action] = $this->splitPermission($permission);
        if ($module === null || $action === null) {
            return false;
        }

        $modulePermissions = is_array($structuredPermissions['modules'] ?? null)
            ? $structuredPermissions['modules']
            : [];

        foreach ($this->buildModuleVariants($module) as $moduleVariant) {
            $grantedPermissions = $modulePermissions[$moduleVariant] ?? null;
            if (!is_array($grantedPermissions)) {
                continue;
            }

            if (in_array('*', $grantedPermissions, true) || in_array($action, $grantedPermissions, true)) {
                return true;
            }

            foreach ($grantedPermissions as $grantedPermission) {
                if (!is_string($grantedPermission) || $grantedPermission === '') {
                    continue;
                }

                if (str_ends_with($grantedPermission, '*')) {
                    $prefix = substr($grantedPermission, 0, -1);
                    if ($prefix !== false && str_starts_with($action, $prefix)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function toPublicContext(array $accessContext): array
    {
        return [
            'organization_id' => (int) ($accessContext['organization_id'] ?? 0),
            'available_modules' => array_values(array_filter(
                $accessContext['available_modules'] ?? [],
                static fn (mixed $module): bool => is_string($module) && $module !== ''
            )),
            'permission_count' => (int) ($accessContext['permission_count'] ?? 0),
            'is_read_only' => (bool) ($accessContext['is_read_only'] ?? true),
            'allowed_action_types' => array_values(array_filter(
                $accessContext['allowed_action_types'] ?? [],
                static fn (mixed $actionType): bool => is_string($actionType) && $actionType !== ''
            )),
        ];
    }

    private function hasMutationPermission(array $permissions, array $structuredPermissions = []): bool
    {
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }

            if ($permission === '*' || $permission === 'admin.*') {
                return true;
            }

            foreach (self::MUTATION_SUFFIXES as $suffix) {
                if (str_ends_with($permission, $suffix)) {
                    return true;
                }
            }
        }

        foreach (['system', 'modules'] as $section) {
            $grantedPermissions = $structuredPermissions[$section] ?? null;
            if (!is_array($grantedPermissions)) {
                continue;
            }

            if ($section === 'system') {
                foreach ($grantedPermissions as $permission) {
                    if (!is_string($permission)) {
                        continue;
                    }

                    if ($permission === '*' || $permission === 'admin.*') {
                        return true;
                    }

                    foreach (self::MUTATION_SUFFIXES as $suffix) {
                        if (str_ends_with($permission, $suffix)) {
                            return true;
                        }
                    }
                }

                continue;
            }

            foreach ($grantedPermissions as $modulePermissions) {
                if (!is_array($modulePermissions)) {
                    continue;
                }

                if (in_array('*', $modulePermissions, true)) {
                    return true;
                }

                foreach ($modulePermissions as $permission) {
                    if (!is_string($permission)) {
                        continue;
                    }

                    foreach (self::MUTATION_SUFFIXES as $suffix) {
                        if (str_ends_with('.' . $permission, $suffix) || str_ends_with($permission, ltrim($suffix, '.'))) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function resolveModules(array $flatPermissions, array $structuredPermissions): array
    {
        $modules = [];

        foreach (($structuredPermissions['modules'] ?? []) as $module => $permissions) {
            if (is_string($module) && $module !== '') {
                $modules[] = $module;
            }
        }

        foreach ($flatPermissions as $permission) {
            if (!is_string($permission) || $permission === '') {
                continue;
            }

            $normalizedPermission = str_starts_with($permission, 'admin.')
                ? substr($permission, 6)
                : $permission;

            $parts = explode('.', $normalizedPermission);
            $module = $parts[0] ?? null;
            if (!is_string($module) || $module === '') {
                continue;
            }

            $modules[] = match ($module) {
                'schedule-management' => 'schedules',
                'warehouse' => 'warehouse',
                'projects' => 'projects',
                'contracts' => 'contracts',
                'materials' => 'materials',
                'reports' => 'reports',
                'payments' => 'payments',
                'procurement' => 'procurement',
                'notifications' => 'notifications',
                'users' => 'users',
                default => $module,
            };
        }

        $modules = array_values(array_unique(array_filter($modules, static fn (mixed $module): bool => is_string($module) && $module !== '')));
        sort($modules);

        return $modules;
    }

    private function buildPermissionVariants(string $permission): array
    {
        $variants = [$permission];

        if (str_starts_with($permission, 'admin.')) {
            $variants[] = substr($permission, 6);
        } else {
            $variants[] = 'admin.' . $permission;
        }

        return array_values(array_unique(array_filter(
            $variants,
            static fn (mixed $variant): bool => is_string($variant) && $variant !== ''
        )));
    }

    private function splitPermission(string $permission): array
    {
        $normalizedPermission = str_starts_with($permission, 'admin.')
            ? substr($permission, 6)
            : $permission;

        $parts = explode('.', $normalizedPermission, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    private function buildModuleVariants(string $module): array
    {
        $variants = [$module];

        $mappedModule = match ($module) {
            'schedules' => 'schedule-management',
            'schedule' => 'schedule-management',
            'warehouse' => 'basic-warehouse',
            default => null,
        };

        if (is_string($mappedModule) && $mappedModule !== '') {
            $variants[] = $mappedModule;
        }

        return array_values(array_unique($variants));
    }
}
