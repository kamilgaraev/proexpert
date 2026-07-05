<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

final class RolePermissionNormalizer
{
    private const MODULE_PERMISSION_DEPENDENCIES = [
        'site_requests' => [
            'site_requests.view' => [
                'site_requests.statistics',
            ],
            'site_requests.edit' => [
                'site_requests.view',
            ],
            'site_requests.delete' => [
                'site_requests.view',
            ],
            'site_requests.approve' => [
                'site_requests.view',
            ],
            'site_requests.reject' => [
                'site_requests.view',
            ],
            'site_requests.assign' => [
                'site_requests.view',
            ],
            'site_requests.change_status' => [
                'site_requests.view',
            ],
            'site_requests.files.upload' => [
                'site_requests.edit',
            ],
            'site_requests.files.delete' => [
                'site_requests.edit',
            ],
            'site_requests.export' => [
                'site_requests.view',
            ],
            'site_requests.templates.manage' => [
                'site_requests.templates.view',
            ],
            'site_requests.calendar.export' => [
                'site_requests.calendar.view',
            ],
        ],
    ];

    private const INTERFACE_SYSTEM_PERMISSIONS = [
        'admin' => [
            'admin.access',
            'admin.view',
            'dashboard.view',
        ],
    ];

    private const INTERFACE_GATE_PERMISSIONS = [
        'admin' => [
            'admin.access',
            'admin.view',
        ],
    ];

    public static function normalizeSystemPermissions(array $systemPermissions, array $interfaceAccess): array
    {
        $permissions = self::normalizeStringList($systemPermissions);
        $interfaces = self::normalizeStringList($interfaceAccess);

        foreach (self::INTERFACE_GATE_PERMISSIONS as $interface => $interfacePermissions) {
            if (in_array($interface, $interfaces, true)) {
                continue;
            }

            $permissions = array_values(array_filter(
                $permissions,
                static fn (string $permission): bool => ! in_array($permission, $interfacePermissions, true)
            ));
        }

        foreach ($interfaces as $interface) {
            foreach (self::INTERFACE_SYSTEM_PERMISSIONS[$interface] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public static function normalizeModulePermissions(array $modulePermissions): array
    {
        $normalized = [];

        foreach ($modulePermissions as $module => $permissions) {
            if (! is_string($module) || trim($module) === '' || ! is_array($permissions)) {
                continue;
            }

            $normalized[$module] = self::normalizeModulePermissionList($module, $permissions);
        }

        return $normalized;
    }

    public static function interfaceSystemPermissions(array $interfaceAccess): array
    {
        $permissions = [];

        foreach (self::normalizeStringList($interfaceAccess) as $interface) {
            foreach (self::INTERFACE_SYSTEM_PERMISSIONS[$interface] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    private static function normalizeModulePermissionList(string $module, array $permissions): array
    {
        $permissions = self::normalizeStringList($permissions);
        $dependencyMap = self::MODULE_PERMISSION_DEPENDENCIES[self::normalizeModuleKey($module)] ?? [];
        $cursor = 0;

        while (isset($permissions[$cursor])) {
            foreach ($dependencyMap[$permissions[$cursor]] ?? [] as $dependency) {
                if (! in_array($dependency, $permissions, true)) {
                    $permissions[] = $dependency;
                }
            }

            $cursor++;
        }

        return array_values(array_unique($permissions));
    }

    private static function normalizeModuleKey(string $module): string
    {
        return str_replace('-', '_', trim($module));
    }

    private static function normalizeStringList(array $items): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn ($item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                $items
            )
        )));
    }
}
