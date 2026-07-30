<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Infrastructure\Definitions\ProductionReportDefinitionBindingAssembler;
use Illuminate\Support\ServiceProvider;

final class ReportingContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportErrorCatalog::class);
        $this->app->singleton(ReportErrorResponseFactory::class);
        $this->app->singleton(ReportAccessService::class);
        $this->app->singleton(ReportExecutionContextFactory::class);
        $this->app->singleton(
            ReportDefinitionBindingAssembler::class,
            ProductionReportDefinitionBindingAssembler::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
