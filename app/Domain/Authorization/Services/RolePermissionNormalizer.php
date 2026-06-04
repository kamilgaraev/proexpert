<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

final class RolePermissionNormalizer
{
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
                static fn (string $permission): bool => !in_array($permission, $interfacePermissions, true)
            ));
        }

        foreach ($interfaces as $interface) {
            foreach (self::INTERFACE_SYSTEM_PERMISSIONS[$interface] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
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
