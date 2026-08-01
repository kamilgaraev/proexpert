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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
