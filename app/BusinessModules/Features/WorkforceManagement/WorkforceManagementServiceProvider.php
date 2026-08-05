<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\WorkforceReportDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionReportBindingFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessOptionsService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessReportBindingFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityOptionsService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityReportBindingFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkforceManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkforceManagementModule::class);
        $this->app->scoped(PayrollReadinessDatabasePort::class, static fn ($app): DatabasePayrollReadinessAdapter => new DatabasePayrollReadinessAdapter(
            $app['db']->connection(), $app->make(PayrollReadinessFormula::class), $app->make(PayrollSourceRateFormula::class),
        ));
        $this->app->singleton(PayrollReadinessCandidateContract::class);
        $this->app->scoped(PayrollReadinessReportBindingFactory::class);
        $this->app->scoped(PayrollReadinessPublishedRuntimeBindingRegistrar::class);
        $this->app->scoped(PayrollReadinessOptionsService::class, static fn ($app): PayrollReadinessOptionsService => new PayrollReadinessOptionsService($app['db']->connection()));
        $this->app->scoped(WorkforceReportDatabasePort::class, static fn ($app): DatabaseWorkforceReportAdapter => new DatabaseWorkforceReportAdapter(
            $app['db']->connection(),
            $app->make(Reporting\Formulas\WorkforceCapacityFormula::class),
            $app->make(Reporting\Formulas\AttendanceExecutionFormula::class),
        ));
        $this->app->singleton(WorkforceCapacityCandidateContract::class);
        $this->app->scoped(WorkforceCapacityReportBindingFactory::class);
        $this->app->scoped(WorkforceCapacityPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(AttendanceExecutionCandidateContract::class);
        $this->app->scoped(AttendanceExecutionReportBindingFactory::class);
        $this->app->scoped(AttendanceExecutionPublishedRuntimeBindingRegistrar::class);
        $this->app->scoped(WorkforceCapacityOptionsService::class, static fn ($app): WorkforceCapacityOptionsService => new WorkforceCapacityOptionsService($app['db']->connection()));
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
        $this->app->afterResolving(ReportDefinitionBindingAssembler::class, function (ReportDefinitionBindingAssembler $assembler): void {
            $this->app->make(PayrollReadinessPublishedRuntimeBindingRegistrar::class)->register($assembler);
            $this->app->make(WorkforceCapacityPublishedRuntimeBindingRegistrar::class)->register($assembler);
            $this->app->make(AttendanceExecutionPublishedRuntimeBindingRegistrar::class)->register($assembler);
        });
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
