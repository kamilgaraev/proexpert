<?php

namespace App\BusinessModules\Features\AdvancedReports;

use Illuminate\Support\ServiceProvider;

class AdvancedReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем сервисы модуля продвинутых отчетов
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты в routes/api/v1/admin/reports.php
        // Дополнительная логика загрузки, если необходимо
    }
}
