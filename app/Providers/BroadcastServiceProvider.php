<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::routes([
            'prefix' => 'api/v1/admin',
            'middleware' => ['api', 'auth:api_admin', 'auth.jwt:api_admin', 'throttle:dashboard'],
        ]);

        Broadcast::routes([
            'prefix' => 'api/v1/landing',
            'middleware' => ['api', 'auth:api_landing', 'auth.jwt:api_landing', 'throttle:dashboard'],
        ]);

        require base_path('routes/channels.php');
    }
}
