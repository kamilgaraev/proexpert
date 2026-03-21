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
        $isReadOnly = !$this->hasMutationPermission($flatPermissions);

        return [
            'organization_id' => $organizationId,
            'can_use_assistant' => $user->belongsToOrganization($organizationId),
            'permissions_flat' => $flatPermissions,
            'permissions_structured' => is_array($structuredPermissions) ? $structuredPermissions : [],
            'available_modules' => $this->resolveModules($flatPermissions, is_array($structuredPermissions) ? $structuredPermissions : []),
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

        $permissions = $accessContext['permissions_flat'] ?? [];
        if (!is_array($permissions)) {
            return false;
        }

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

    private function hasMutationPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }

            foreach (self::MUTATION_SUFFIXES as $suffix) {
                if (str_ends_with($permission, $suffix)) {
                    return true;
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
}
