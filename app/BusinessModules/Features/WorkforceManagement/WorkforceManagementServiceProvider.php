<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkforceManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkforceManagementModule::class);
        $this->app->singleton(
            Reporting\PayrollReadiness\Contracts\PayrollReadinessEvidenceSource::class,
            Reporting\PayrollReadiness\Services\EloquentPayrollReadinessEvidenceSource::class,
        );
        $this->app->singleton(
            Reporting\PayrollReadiness\Contracts\PayrollReadinessSnapshotStore::class,
            Reporting\PayrollReadiness\Services\EloquentPayrollReadinessSnapshotStore::class,
        );
        $this->app->singleton(
            Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition::class,
            static fn (): Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition => Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition::v1(),
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityClock::class,
            Reporting\Capacity\Services\SystemWorkforceCapacityClock::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource::class,
            Reporting\Capacity\Services\EloquentWorkforceCapacityCurrentSource::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityPolicySource::class,
            Reporting\Capacity\Services\EloquentWorkforceCapacityPolicySource::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore::class,
            Reporting\Capacity\Services\EloquentWorkforceCapacitySnapshotStore::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityCohortLock::class,
            Reporting\Capacity\Services\PostgresWorkforceCapacityCohortLock::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway::class,
            static fn ($app): Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway => new Reporting\Capacity\Services\EloquentWorkforceCapacityRequestScopedFrozenSourceGateway(
                $app['db']->connection(),
            ),
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityFrozenCaptureWriter::class,
            Reporting\Capacity\Services\RequestScopedWorkforceCapacityFrozenCaptureWriter::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityDeferredSourceReader::class,
            Reporting\Capacity\Services\RequestScopedWorkforceCapacityDeferredSourceReader::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityLifecycleCaptureCoordinator::class,
            Reporting\Capacity\Services\RequestScopedWorkforceCapacityLifecycleCaptureCoordinator::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureStore::class,
            Reporting\Capacity\Services\EloquentWorkforceCapacityDeferredCaptureStore::class,
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher::class,
            static fn ($app): Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher => new Reporting\Capacity\Services\LaravelWorkforceCapacityDeferredCaptureDispatcher(
                $app['db']->connection(),
            ),
        );
        $this->app->singleton(
            Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary::class,
            static fn ($app): Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary => new Reporting\Capacity\Services\WorkforceCapacitySnapshotService(
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource::class),
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacityPolicySource::class),
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore::class),
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacityClock::class),
                64,
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacityFrozenCaptureWriter::class),
                $app->make(Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher::class),
                128,
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new Reporting\Capacity\Jobs\RecoverWorkforceCapacityCapturesJob)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->onOneServer();
        });
    }
}
