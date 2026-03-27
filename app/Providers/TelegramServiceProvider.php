<?php

namespace App\Providers;

use App\Services\Notification\TelegramService;
use App\Services\Logging\LoggingService;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramService::class, function ($app) {
            return new TelegramService(
                $app->make(LoggingService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
