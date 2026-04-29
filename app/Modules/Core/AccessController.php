<?php

namespace App\Modules\Core;

use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AccessController
{
    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        return $this->getActiveModules($organizationId)
            ->contains(fn (Module $module): bool => $module->slug === $moduleSlug);
    }

    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        foreach ($this->getActiveModules($organizationId) as $module) {
            foreach ((array) $module->permissions as $modulePermission) {
                if ($modulePermission === $permission) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canUserAccessModule(User $user, string $moduleSlug): bool
    {
        $organizationId = $user->current_organization_id;

        if (! $organizationId) {
            return false;
        }

        return $this->hasModuleAccess($organizationId, $moduleSlug);
    }

    public function canUserUsePermission(User $user, string $permission): bool
    {
        $organizationId = $user->current_organization_id;

        if (! $organizationId) {
            return false;
        }

        return $this->hasModulePermission($organizationId, $permission);
    }

    public function getActiveModules(int $organizationId): Collection
    {
        $cacheKey = "org_effective_active_modules_{$organizationId}";

        return Cache::remember($cacheKey, 60, function () use ($organizationId): Collection {
            return $this->entitlements()->getEffectiveModules($organizationId);
        });
    }

    public function getUserAvailablePermissions(User $user): array
    {
        $organizationId = $user->current_organization_id;

        if (! $organizationId) {
            return [];
        }

        $cacheKey = "user_available_permissions_{$user->id}_{$organizationId}";

        return Cache::remember($cacheKey, 60, function () use ($organizationId): array {
            $activeModules = $this->getActiveModules($organizationId);
            $permissions = [];

            foreach ($activeModules as $module) {
                if ($module && $module->permissions) {
                    $permissions = array_merge($permissions, $module->permissions);
                }
            }

            return array_values(array_unique($permissions));
        });
    }

    public function checkDependencies(int $organizationId, Module $module): array
    {
        $dependencies = $module->dependencies ?? [];
        $conflicts = $module->conflicts ?? [];
        $missing = [];
        $found = [];

        foreach ($dependencies as $dependencySlug) {
            if (! $this->hasModuleAccess($organizationId, $dependencySlug)) {
                $missing[] = $dependencySlug;
            }
        }

        foreach ($conflicts as $conflictSlug) {
            if ($this->hasModuleAccess($organizationId, $conflictSlug)) {
                $found[] = $conflictSlug;
            }
        }

        return [
            'missing_dependencies' => $missing,
            'conflicts' => $found,
            'is_already_active' => $this->hasModuleAccess($organizationId, $module->slug),
        ];
    }

    public function checkConflicts(int $organizationId, Module $module): array
    {
        $found = [];

        foreach ($module->conflicts ?? [] as $conflictSlug) {
            if ($this->hasModuleAccess($organizationId, $conflictSlug)) {
                $found[] = $conflictSlug;
            }
        }

        return $found;
    }

    public function clearAccessCache(int $organizationId): void
    {
        $specificKeys = [
            "org_effective_active_modules_{$organizationId}",
            "org_active_modules_{$organizationId}",
            "active_modules_{$organizationId}",
            "modules_with_status_{$organizationId}",
        ];

        foreach ($specificKeys as $key) {
            Cache::forget($key);
        }

        $organization = Organization::find($organizationId);

        if ($organization && Schema::hasTable('users') && Schema::hasTable('organization_user')) {
            $userIds = $organization->users()->pluck('users.id');

            foreach ($userIds as $userId) {
                Cache::forget("user_permissions_{$userId}_{$organizationId}");
                Cache::forget("user_permissions_full_effective_{$userId}_{$organizationId}");
                Cache::forget("user_permissions_full_{$userId}_{$organizationId}");
                Cache::forget("user_available_permissions_{$userId}_{$organizationId}");
            }
        }

        try {
            Cache::tags(['module_access', "org_{$organizationId}"])->flush();
        } catch (\Exception $e) {
            $this->clearWildcardCache("org_module_access_{$organizationId}_");
            $this->clearWildcardCache("org_module_permission_{$organizationId}_");
            $this->clearWildcardCache('user_available_permissions_', "_{$organizationId}");
        }
    }

    private function clearWildcardCache(string $prefix, string $suffix = ''): void
    {
        try {
            if (config('cache.default') === 'redis') {
                $redis = app(RedisFactory::class)->connection();
                $pattern = $prefix.'*'.$suffix;
                $keys = $redis->command('keys', [$pattern]);

                if (is_array($keys) && $keys !== []) {
                    $redis->command('del', [$keys]);
                }
            }
        } catch (\Exception $e) {
        }
    }

    private function entitlements(): OrganizationEntitlementService
    {
        return app(OrganizationEntitlementService::class);
    }
}
