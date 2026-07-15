<?php

declare(strict_types=1);

use App\Modules\Services\ModulePermissionService;

if (! function_exists('hasModuleAccess')) {
    function hasModuleAccess(string $moduleSlug, ?\App\Models\User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && app(ModulePermissionService::class)->userHasModuleAccess($user, $moduleSlug);
    }
}

if (! function_exists('hasModulePermission')) {
    function hasModulePermission(string $permission, ?\App\Models\User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && app(ModulePermissionService::class)->userHasPermission($user, $permission);
    }
}

if (! function_exists('getActiveModules')) {
    function getActiveModules(?\App\Models\User $user = null): \Illuminate\Support\Collection
    {
        $user ??= auth()->user();

        return $user === null ? collect() : app(ModulePermissionService::class)->getUserActiveModules($user);
    }
}

if (! function_exists('getUserPermissions')) {
    function getUserPermissions(?\App\Models\User $user = null): array
    {
        $user ??= auth()->user();

        return $user === null ? [] : app(ModulePermissionService::class)->getUserAvailablePermissions($user);
    }
}

if (! function_exists('moduleRoute')) {
    function moduleRoute(string $routeName, array $parameters = []): string
    {
        return route("api.v1.landing.modules.{$routeName}", $parameters);
    }
}

if (! function_exists('refreshModuleRegistry')) {
    function refreshModuleRegistry(): void
    {
        app(\App\Modules\Core\ModuleRegistry::class)->refreshRegistry();
    }
}
