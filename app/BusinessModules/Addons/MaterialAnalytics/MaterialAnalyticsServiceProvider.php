<?php

namespace App\BusinessModules\Addons\MaterialAnalytics;

use Illuminate\Support\ServiceProvider;

class MaterialAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaterialAnalyticsModule::class);
    }

    public function boot(): void
    {
        // Модуль использует универсальный middleware module.access:material-analytics
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
