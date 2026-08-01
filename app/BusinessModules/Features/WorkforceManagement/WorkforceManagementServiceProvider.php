<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

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
            Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary::class,
            Reporting\Capacity\Services\WorkforceCapacitySnapshotService::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
