<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;

class DashboardWidgetsRegistry
{
    private const CACHE_KEY = 'dashboard_widgets_registry_v1';
    private const CACHE_TTL_SECONDS = 3600;

    public function get(array $userRoles = []): array
    {
        $raw = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return Config::get('dashboard.widgets_registry');
        });

        $version = (int)($raw['version'] ?? 1);
        $widgets = $raw['widgets'] ?? [];

        if (!empty($userRoles)) {
            $widgets = array_values(array_filter($widgets, function ($w) use ($userRoles) {
                if (empty($w['roles'])) {
                    return true;
                }
                foreach ($w['roles'] as $role) {
                    if (in_array($role, $userRoles, true)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return [
            'version' => $version,
            'widgets' => $widgets,
        ];
    }
}


