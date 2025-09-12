<?php

namespace App\BusinessModules\Features\BasicReports;

use Illuminate\Support\ServiceProvider;

class BasicReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем сервисы модуля базовых отчетов
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты в routes/api/v1/admin/reports.php
        // Дополнительная логика загрузки, если необходимо
    }
}
