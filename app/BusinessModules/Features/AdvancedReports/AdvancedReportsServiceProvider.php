<?php

namespace App\BusinessModules\Features\AdvancedReports;

use Illuminate\Support\ServiceProvider;
use App\Services\Report\ReportDataSourceRegistry;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Report\CustomReportExecutionService;
use App\Services\Report\Builders\ReportQueryBuilder;
use App\Services\Report\Builders\ReportFilterBuilder;
use App\Services\Report\Builders\ReportAggregationBuilder;
use App\Services\Export\CsvExporterService;

class AdvancedReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CsvExporterService::class, function ($app) {
            return new CsvExporterService(
                $app->make(\App\Services\Logging\LoggingService::class)
            );
        });

        $this->app->singleton(ReportDataSourceRegistry::class, function ($app) {
            return new ReportDataSourceRegistry();
        });

        $this->app->singleton(ReportFilterBuilder::class, function ($app) {
            return new ReportFilterBuilder(
                $app->make(ReportDataSourceRegistry::class)
            );
        });

        $this->app->singleton(ReportAggregationBuilder::class, function ($app) {
            return new ReportAggregationBuilder(
                $app->make(ReportDataSourceRegistry::class)
            );
        });

        $this->app->singleton(ReportQueryBuilder::class, function ($app) {
            return new ReportQueryBuilder(
                $app->make(ReportDataSourceRegistry::class),
                $app->make(ReportFilterBuilder::class),
                $app->make(ReportAggregationBuilder::class)
            );
        });

        $this->app->singleton(CustomReportBuilderService::class, function ($app) {
            return new CustomReportBuilderService(
                $app->make(ReportDataSourceRegistry::class),
                $app->make(ReportQueryBuilder::class),
                $app->make(\App\Services\Logging\LoggingService::class)
            );
        });

        $this->app->singleton(CustomReportExecutionService::class, function ($app) {
            return new CustomReportExecutionService(
                $app->make(CustomReportBuilderService::class),
                $app->make(\App\Services\Export\CsvExporterService::class),
                $app->make(\App\Services\Export\ExcelExporterService::class),
                $app->make(\App\Services\Logging\LoggingService::class)
            );
        });
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты в routes/api/v1/admin/reports.php
        // и routes/api/v1/admin/custom-reports.php
    }
}
