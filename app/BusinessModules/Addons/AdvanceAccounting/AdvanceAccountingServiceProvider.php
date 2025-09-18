<?php

namespace App\BusinessModules\Addons\AdvanceAccounting;

use Illuminate\Support\ServiceProvider;

class AdvanceAccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdvanceAccountingModule::class);
    }

    public function boot(): void
    {
        // Модуль использует существующие маршруты и контроллеры для подотчетных средств
        // Дополнительная логика инициализации может быть добавлена здесь
    }
}
