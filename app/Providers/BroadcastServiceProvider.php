<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Broadcasting routes для Admin Panel
        // Используем dashboard rate limiter для WebSocket авторизации
        Broadcast::routes([
            'prefix' => 'api/v1/admin',
            'middleware' => ['api', 'auth:api_admin', 'auth.jwt:api_admin', 'throttle:dashboard'],
        ]);

        // Broadcasting routes для Landing/LK
        Broadcast::routes([
            'prefix' => 'api/v1/landing',
            'middleware' => ['api', 'auth:api_landing', 'auth.jwt:api_landing', 'throttle:dashboard'],
        ]);

        // Стандартный broadcasting route (fallback)
        Broadcast::routes([
            'middleware' => ['api', 'auth:api_admin,api_landing', 'throttle:dashboard'],
        ]);

        require base_path('routes/channels.php');
    }
}

