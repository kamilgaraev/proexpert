<?php

namespace App\BusinessModules\Core\Reports;

use App\Services\Export\CsvExporterService;
use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CsvExporterService::class, function ($app) {
            return new CsvExporterService(
                $app->make(\App\Services\Logging\LoggingService::class)
            );
        });
    }

    public function boot(): void
    {
    }
}
