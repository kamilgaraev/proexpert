<?php

namespace App\BusinessModules\Addons\ActReporting;

use Illuminate\Support\ServiceProvider;

class ActReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ActReportingModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для актов отчетности
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
