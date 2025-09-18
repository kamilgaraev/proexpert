<?php

namespace App\BusinessModules\Features\CatalogManagement;

use Illuminate\Support\ServiceProvider;

class CatalogManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogManagementModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для справочников
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
