<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Reporting;

use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\ReportEngine;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\DataAggregator;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\KPICalculator;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем DataAggregator как singleton
        $this->app->singleton(DataAggregator::class, function ($app) {
            return new DataAggregator();
        });

        // Регистрируем KPICalculator как singleton
        $this->app->singleton(KPICalculator::class, function ($app) {
            return new KPICalculator($app->make(DataAggregator::class));
        });

        // Регистрируем ReportEngine как singleton
        $this->app->singleton(ReportEngine::class, function ($app) {
            return new ReportEngine(
                $app->make(DataAggregator::class),
                $app->make(KPICalculator::class)
            );
        });
    }

    public function boot(): void
    {
        // Здесь можно добавить дополнительную настройку
        // Например, планировщики отчетов, слушатели событий и т.д.
    }

    public function provides(): array
    {
        return [
            DataAggregator::class,
            KPICalculator::class,
            ReportEngine::class,
        ];
    }
}
