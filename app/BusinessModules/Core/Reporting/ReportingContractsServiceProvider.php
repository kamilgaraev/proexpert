<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\EloquentReportActorLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSnapshotSealStore;
use Illuminate\Support\ServiceProvider;

final class ReportingContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportSourceSnapshotStore::class, EloquentReportSourceSnapshotStore::class);
        $this->app->singleton(ReportSnapshotSealStore::class, EloquentReportSnapshotSealStore::class);
        $this->app->singleton(
            ReportSourceSnapshotStreamingStore::class,
            static fn ($app): ReportSourceSnapshotStreamingStore => $app->make(ReportSourceSnapshotStore::class),
        );
        $this->app->singleton(ReportErrorCatalog::class);
        $this->app->singleton(ReportErrorResponseFactory::class);
        $this->app->singleton(ReportActorLoader::class, EloquentReportActorLoader::class);
        $this->app->singleton(ReportAccessService::class);
        $this->app->singleton(ReportExecutionContextFactory::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
