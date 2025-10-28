<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Broadcasting routes для Admin Panel
        Broadcast::routes([
            'prefix' => 'api/v1/admin',
            'middleware' => ['api', 'auth:api_admin', 'auth.jwt:api_admin'],
        ]);

        // Broadcasting routes для Landing/LK
        Broadcast::routes([
            'prefix' => 'api/v1/landing',
            'middleware' => ['api', 'auth:api_landing', 'auth.jwt:api_landing'],
        ]);

        // Стандартный broadcasting route (fallback)
        Broadcast::routes([
            'middleware' => ['api', 'auth:api_admin,api_landing'],
        ]);

        require base_path('routes/channels.php');
    }
}

