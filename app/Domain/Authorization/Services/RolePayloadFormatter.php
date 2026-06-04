<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Services\PermissionTranslationService;

use function trans_message;

final class RolePayloadFormatter
{
    public function __construct(
        private readonly PermissionTranslationService $permissionTranslator
    ) {
    }

    public function formatSystemRole(string $roleSlug, array $roleData): array
    {
        $roleData['slug'] = $roleData['slug'] ?? $roleSlug;
        $normalizedSystemPermissions = $this->normalizedSystemPermissions($roleData);
        $modulePermissions = $this->modulePermissions($roleData);
        $permissions = $this->translatePermissions(
            $normalizedSystemPermissions,
            $modulePermissions,
            $roleData['interface_access'] ?? []
        );

        return [
            'slug' => $roleSlug,
            'name' => $this->translatedRoleName($roleSlug, $roleData),
            'description' => $this->translatedRoleDescription($roleSlug, $roleData),
            'type' => 'system',
            'is_active' => true,
            'context' => $roleData['context'] ?? 'unknown',
            'interface' => $roleData['interface'] ?? null,
            'interface_access' => $roleData['interface_access'] ?? [],
            'assignable' => $this->isAssignableSystemRole($roleData),
            'permissions' => $permissions,
            'permission_groups' => $this->permissionGroups($permissions),
            'permission_preview' => $this->permissionPreview($permissions),
            'system_permissions_count' => count($normalizedSystemPermissions),
            'module_permissions_count' => $this->countModulePermissions($modulePermissions),
            'has_all_permissions' => in_array('*', $normalizedSystemPermissions, true),
            'has_all_modules' => isset($modulePermissions['*']),
        ];
    }

    public function formatCustomRole(OrganizationCustomRole $role): array
    {
        $systemPermissions = RolePermissionNormalizer::normalizeSystemPermissions(
            $role->system_permissions ?? [],
            $role->interface_access ?? []
        );
        $modulePermissions = is_array($role->module_permissions) ? $role->module_permissions : [];
        $permissions = $this->translatePermissions(
            $systemPermissions,
            $modulePermissions,
            $role->interface_access ?? []
        );

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'type' => 'custom',
            'interface_access' => $role->interface_access ?? [],
            'permissions' => $permissions,
            'permission_groups' => $this->permissionGroups($permissions),
            'permission_preview' => $this->permissionPreview($permissions),
            'system_permissions_count' => count($systemPermissions),
            'module_permissions_count' => $this->countModulePermissions($modulePermissions),
        ];
    }

    public function isAssignableSystemRole(array $roleData): bool
    {
        return ($roleData['assignable'] ?? true) !== false;
    }

    public function translatedRoleName(string $roleSlug, array $roleData): string
    {
        $translation = trans_message("roles.{$roleSlug}.name");

        if ($translation !== "roles.{$roleSlug}.name") {
            return $translation;
        }

        return (string) ($roleData['name'] ?? $this->humanizeSlug($roleSlug));
    }

    public function translatedRoleDescription(string $roleSlug, array $roleData): string
    {
        $translation = trans_message("roles.{$roleSlug}.description");

        if ($translation !== "roles.{$roleSlug}.description") {
            return $translation;
        }

        return (string) ($roleData['description'] ?? '');
    }

    public function translatedPermissionsForRole(array $roleData): array
    {
        return $this->translatePermissions(
            $this->normalizedSystemPermissions($roleData),
            $this->modulePermissions($roleData),
            $roleData['interface_access'] ?? []
        );
    }

    public function permissionGroups(array $permissions): array
    {
        $groups = [];

        if (!empty($permissions['interface_access']) && is_array($permissions['interface_access'])) {
            $groups[] = [
                'slug' => 'interfaces',
                'name' => 'Доступ к интерфейсам',
                'permissions' => $this->permissionItems($permissions['interface_access']),
            ];
        }

        if (!empty($permissions['system_permissions']) && is_array($permissions['system_permissions'])) {
            $groups[] = [
                'slug' => 'system',
                'name' => 'Системные права',
                'permissions' => $this->permissionItems($permissions['system_permissions']),
            ];
        }

        $modulePermissions = $permissions['module_permissions'] ?? [];
        $moduleGroups = $permissions['module_groups'] ?? [];

        if (is_array($modulePermissions)) {
            foreach ($modulePermissions as $module => $modulePermissionList) {
                if (!is_array($modulePermissionList)) {
                    continue;
                }

                $groups[] = [
                    'slug' => (string) $module,
                    'name' => is_array($moduleGroups) && isset($moduleGroups[$module])
                        ? (string) $moduleGroups[$module]
                        : (string) $module,
                    'permissions' => $this->permissionItems($modulePermissionList),
                ];
            }
        }

        return array_values(array_filter(
            $groups,
            static fn (array $group): bool => !empty($group['permissions'])
        ));
    }

    public function permissionPreview(array $permissions, int $limit = 5): array
    {
        $preview = [];

        foreach ($this->permissionGroups($permissions) as $group) {
            foreach ($group['permissions'] as $permission) {
                $preview[] = $permission['name'];

                if (count($preview) >= $limit) {
                    return $preview;
                }
            }
        }

        return $preview;
    }

    public function normalizedSystemPermissions(array $roleData): array
    {
        return RolePermissionNormalizer::normalizeSystemPermissions(
            $roleData['system_permissions'] ?? [],
            $roleData['interface_access'] ?? []
        );
    }

    public function countModulePermissions(array $modulePermissions): int
    {
        $count = 0;

        foreach ($modulePermissions as $module => $permissions) {
            if ($module === '*' && is_array($permissions) && in_array('*', $permissions, true)) {
                return 999;
            }

            if (is_array($permissions)) {
                $count += count($permissions);
            }
        }

        return $count;
    }

    private function translatePermissions(array $systemPermissions, array $modulePermissions, array $interfaceAccess): array
    {
        return $this->permissionTranslator->processPermissionsForFrontend([
            'system_permissions' => $systemPermissions,
            'module_permissions' => $modulePermissions,
            'interface_access' => $interfaceAccess,
        ]);
    }

    private function modulePermissions(array $roleData): array
    {
        return is_array($roleData['module_permissions'] ?? null) ? $roleData['module_permissions'] : [];
    }

    private function permissionItems(array $permissions): array
    {
        $items = [];

        foreach ($permissions as $slug => $name) {
            if (!is_string($slug) || !is_string($name)) {
                continue;
            }

            $items[] = [
                'slug' => $slug,
                'name' => $name,
            ];
        }

        return $items;
    }

    private function humanizeSlug(string $roleSlug): string
    {
        return str_replace('_', ' ', $roleSlug);
    }
}
