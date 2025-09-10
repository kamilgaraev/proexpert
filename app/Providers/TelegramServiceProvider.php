<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Notification\TelegramService;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramService::class, function ($app) {
            return new TelegramService();
        });
    }

    public function boot(): void
    {
        //
    }
}
