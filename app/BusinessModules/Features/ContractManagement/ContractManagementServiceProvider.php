<?php

namespace App\BusinessModules\Features\ContractManagement;

use Illuminate\Support\ServiceProvider;

class ContractManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractManagementModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для контрактов, соглашений и спецификаций
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
