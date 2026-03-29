<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\SystemAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SystemAdminRoleService
{
    private const ROLES_PATH = 'config/RoleDefinitions/system_admin';
    private const CACHE_KEY = 'system_admin_roles';
    private const CACHE_TTL = 3600;

    public function getAllRoles(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): Collection => $this->scanRoles());
    }

    public function getRole(?string $slug): ?array
    {
        if (!$slug) {
            return null;
        }

        return $this->getAllRoles()->get($slug);
    }

    public function roleExists(string $slug): bool
    {
        return $this->getAllRoles()->has($slug);
    }

    public function resolveRoleSlug(SystemAdmin $systemAdmin): string
    {
        $role = $systemAdmin->role ?? null;

        return is_string($role) && $role !== '' ? $role : 'super_admin';
    }

    public function isSuperAdmin(SystemAdmin $systemAdmin): bool
    {
        return $this->matchesPermission('*', $this->getPermissions($this->resolveRoleSlug($systemAdmin)));
    }

    public function canAccessInterface(SystemAdmin $systemAdmin, string $interface): bool
    {
        $role = $this->getRole($this->resolveRoleSlug($systemAdmin));

        if (!$role) {
            return false;
        }

        return in_array($interface, $role['interface_access'] ?? [], true);
    }

    public function hasPermission(SystemAdmin $systemAdmin, string $permission): bool
    {
        if (!$systemAdmin->isActive()) {
            return false;
        }

        return $this->matchesPermission($permission, $this->getPermissions($this->resolveRoleSlug($systemAdmin)));
    }

    public function canManageRole(SystemAdmin $systemAdmin, string $targetRole): bool
    {
        $role = $this->getRole($this->resolveRoleSlug($systemAdmin));

        if (!$role) {
            return false;
        }

        $canManageRoles = $role['hierarchy']['can_manage_roles'] ?? [];
        $cannotManageRoles = $role['hierarchy']['cannot_manage'] ?? [];

        if (in_array('*', $cannotManageRoles, true) || in_array($targetRole, $cannotManageRoles, true)) {
            return false;
        }

        return in_array('*', $canManageRoles, true) || in_array($targetRole, $canManageRoles, true);
    }

    public function getPermissionLabels(SystemAdmin $systemAdmin): array
    {
        return $this->getPermissions($this->resolveRoleSlug($systemAdmin));
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function getPermissions(string $roleSlug): array
    {
        $role = $this->getRole($roleSlug);

        if (!$role) {
            return [];
        }

        $permissions = $role['system_permissions'] ?? [];
        $modulePermissions = $role['module_permissions'] ?? [];

        foreach ($modulePermissions as $modulePermissionList) {
            if (!is_array($modulePermissionList)) {
                continue;
            }

            $permissions = array_merge($permissions, $modulePermissionList);
        }

        return array_values(array_unique(array_filter($permissions, 'is_string')));
    }

    private function matchesPermission(string $requestedPermission, array $grantedPermissions): bool
    {
        foreach ($grantedPermissions as $grantedPermission) {
            if ($grantedPermission === '*') {
                return true;
            }

            if ($grantedPermission === $requestedPermission) {
                return true;
            }

            if (Str::endsWith($grantedPermission, '.*')) {
                $prefix = Str::beforeLast($grantedPermission, '.*');

                if (Str::startsWith($requestedPermission, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function scanRoles(): Collection
    {
        $roles = collect();
        $path = base_path(self::ROLES_PATH);

        if (!File::isDirectory($path)) {
            return $roles;
        }

        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $payload = json_decode(File::get($file->getPathname()), true);

            if (!is_array($payload) || !isset($payload['slug']) || !is_string($payload['slug'])) {
                continue;
            }

            $roles->put($payload['slug'], $payload);
        }

        return $roles;
    }
}
