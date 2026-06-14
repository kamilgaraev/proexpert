<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::night();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (! $user) {
                return false;
            }

            return $user->isSystemAdmin() ||
                   $user->hasPermission('notifications.view_analytics');
        });
    }
}
