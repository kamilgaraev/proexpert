<?php

namespace App\BusinessModules\Features\ProjectManagement;

use Illuminate\Support\ServiceProvider;

class ProjectManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProjectManagementModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для проектов
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
