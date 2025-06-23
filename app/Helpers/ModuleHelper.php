<?php

if (!function_exists('hasModuleAccess')) {
    function hasModuleAccess(string $moduleSlug): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        
        if (!$user || !$user->current_organization_id) {
            return false;
        }

        $moduleService = app(\App\Services\Landing\OrganizationModuleService::class);
        return $moduleService->hasModuleAccess($user->current_organization_id, $moduleSlug);
    }
}

if (!function_exists('hasModulePermission')) {
    function hasModulePermission(string $permission): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        
        if (!$user || !$user->current_organization_id) {
            return false;
        }

        $moduleService = app(\App\Services\Landing\OrganizationModuleService::class);
        return $moduleService->hasModulePermission($user->current_organization_id, $permission);
    }
}

if (!function_exists('getActiveModules')) {
    function getActiveModules(): array
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        
        if (!$user || !$user->current_organization_id) {
            return [];
        }

        $moduleService = app(\App\Services\Landing\OrganizationModuleService::class);
        return $moduleService->getOrganizationActiveModules($user->current_organization_id)->toArray();
    }
}

if (!function_exists('moduleRoute')) {
    function moduleRoute(string $routeName, array $parameters = []): string
    {
        return route("api.v1.landing.modules.{$routeName}", $parameters);
    }
} 