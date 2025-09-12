<?php

namespace App\BusinessModules\Core\MultiOrganization;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Core\MultiOrganization\Services\MultiOrganizationHelperService;

class MultiOrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем helper сервис как singleton
        $this->app->singleton(MultiOrganizationHelperService::class, function ($app) {
            return new MultiOrganizationHelperService($app->make(\App\Services\Landing\MultiOrganizationService::class));
        });
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты в routes/api/v1/landing/multi_organization.php
        // Дополнительная логика загрузки, если необходимо
    }
}
