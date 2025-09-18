<?php

namespace App\BusinessModules\Features\TimeTracking;

use Illuminate\Support\ServiceProvider;

class TimeTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TimeTrackingModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для учета времени
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
