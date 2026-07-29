<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\EffectiveAssignmentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\WorkforceReportDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class WorkforceManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkforceManagementModule::class);
        $this->app->scoped(
            WorkforceReportDatabasePort::class,
            static fn ($app): DatabaseWorkforceReportAdapter => new DatabaseWorkforceReportAdapter(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(WorkforceCapacityFormula::class),
                $app->make(AttendanceExecutionFormula::class),
            ),
        );
        $this->app->scoped(
            EffectiveAssignmentSource::class,
            static fn ($app): WorkforceReportDatabasePort => $app->make(WorkforceReportDatabasePort::class),
        );
        $this->app->scoped(
            PayrollReadinessDatabasePort::class,
            static fn ($app): DatabasePayrollReadinessAdapter => new DatabasePayrollReadinessAdapter(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(PayrollReadinessFormula::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
