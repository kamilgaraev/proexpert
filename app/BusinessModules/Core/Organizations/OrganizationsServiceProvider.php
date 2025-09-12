<?php

namespace App\BusinessModules\Core\Organizations;

use Illuminate\Support\ServiceProvider;

class OrganizationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем сервисы модуля организаций
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты для организаций
        // Дополнительная логика загрузки, если необходимо
    }
}
