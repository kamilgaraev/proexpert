<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php';
require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php';

use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CancelReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CancelReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportDownloadLinkHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportDrillDownHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRowsHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\RetryReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\RetryReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportReadyDownloadStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingExecutionServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReportingExecutionBindingsTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = dirname(__DIR__, 3);
        $this->app = new Application($basePath);
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'app' => [
                'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
            'reporting_execution' => require $basePath.'/config/reporting_execution.php',
            'queue' => [
                'connections' => [
                    'redis_reports' => [
                        'queue' => 'reports',
                        'job_timeout' => 900,
                        'execution_lease_seconds' => 960,
                        'retry_after' => 1200,
                    ],
                ],
            ],
            'horizon' => [
                'environments' => [
                    'production' => ['supervisor-reports' => ['timeout' => 960]],
                    'local' => ['supervisor-reports' => ['timeout' => 960]],
                ],
            ],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->instance(\Psr\Log\LoggerInterface::class, new NullLogger);

        $provider = new ReportingExecutionServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    #[DataProvider('actionBindings')]
    public function test_execution_action_ports_have_exact_handlers(string $contract, string $handler): void
    {
        self::assertSame($handler, $this->concrete($contract));
    }

    public function test_catalog_remains_owned_by_plan_one_c(): void
    {
        self::assertFalse($this->app->bound(GetReportCatalogAction::class));
    }

    #[DataProvider('executionBindings')]
    public function test_execution_ports_have_exact_concretes(string $contract, string $implementation): void
    {
        self::assertSame($implementation, $this->concrete($contract));
    }

    public function test_provider_is_registered_immediately_after_contracts_provider(): void
    {
        $providers = require base_path('bootstrap/providers.php');
        $contracts = array_search(ReportingContractsServiceProvider::class, $providers, true);
        self::assertIsInt($contracts);
        self::assertSame(ReportingExecutionServiceProvider::class, $providers[$contracts + 1] ?? null);
    }

    public function test_closed_execution_configuration_matches_queue_runtime(): void
    {
        self::assertSame([
            'ttl_seconds' => 86400,
            'poll_after_ms' => 1250,
        ], config('reporting_execution.runs'));
        self::assertSame([
            'batch_size' => 100,
            'lease_seconds' => 60,
            'max_attempts' => 12,
        ], config('reporting_execution.dispatch'));
        self::assertSame(960, config('reporting_execution.execution.lease_seconds'));
        self::assertSame(100, config('reporting_execution.execution.watchdog_batch_size'));
        self::assertSame(3600, config('reporting_execution.artifacts.reconciliation_grace_seconds'));
        self::assertSame(900, config('queue.connections.redis_reports.job_timeout'));
        self::assertSame(1200, config('queue.connections.redis_reports.retry_after'));
        self::assertSame(960, config('horizon.environments.production.supervisor-reports.timeout'));
        self::assertSame(960, config('horizon.environments.local.supervisor-reports.timeout'));
    }

    public function test_empty_resource_registry_and_renderer_registry_resolve_without_request_state(): void
    {
        self::assertInstanceOf(
            LaravelReportScopedResourceAuthorizerRegistry::class,
            $this->app->make(LaravelReportScopedResourceAuthorizerRegistry::class),
        );
        self::assertInstanceOf(ReportExportRendererRegistry::class, $this->app->make(ReportExportRendererRegistry::class));
    }

    private function concrete(string $contract): string
    {
        $binding = $this->app->getBindings()[$contract] ?? null;
        self::assertIsArray($binding, "Missing binding for {$contract}");
        if ($binding['concrete'] instanceof \Closure) {
            $captured = (new \ReflectionFunction($binding['concrete']))->getStaticVariables();
            if (is_string($captured['concrete'] ?? null)) {
                return $captured['concrete'];
            }
        }

        return is_string($binding['concrete']) ? $binding['concrete'] : $this->app->make($contract)::class;
    }

    public static function actionBindings(): array
    {
        return [
            [CreateReportRunAction::class, CreateReportRunHandler::class],
            [GetReportRunAction::class, GetReportRunHandler::class],
            [RetryReportRunAction::class, RetryReportRunHandler::class],
            [CancelReportRunAction::class, CancelReportRunHandler::class],
            [GetReportRowsAction::class, GetReportRowsHandler::class],
            [GetReportDrillDownAction::class, GetReportDrillDownHandler::class],
            [CreateReportExportAction::class, CreateReportExportHandler::class],
            [GetReportExportAction::class, GetReportExportHandler::class],
            [RetryReportExportAction::class, RetryReportExportHandler::class],
            [CancelReportExportAction::class, CancelReportExportHandler::class],
            [CreateReportDownloadLinkAction::class, CreateReportDownloadLinkHandler::class],
        ];
    }

    public static function executionBindings(): array
    {
        return [
            [ReportExecutionClock::class, SystemReportExecutionClock::class],
            [ReportExecutionTelemetry::class, \App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\LaravelReportExecutionTelemetry::class],
            [ReportTransitionAudit::class, \App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit::class],
            [ReportRunStore::class, EloquentReportRunStore::class],
            [ReportExportStore::class, EloquentReportExportStore::class],
            [ReportReadyDownloadStore::class, EloquentReportExportStore::class],
            [ReportRunAsyncContextSeedReader::class, EloquentReportRunAsyncContextSeedReader::class],
            [ReportExportAsyncContextSeedReader::class, EloquentReportExportAsyncContextSeedReader::class],
            [ReportRunLeaseRecoveryStore::class, EloquentReportRunLeaseRecoveryStore::class],
            [ReportExportLeaseRecoveryStore::class, EloquentReportExportLeaseRecoveryStore::class],
            [ReportRunAttemptLifecycleStore::class, EloquentReportRunAttemptLifecycleStore::class],
            [ReportExportAttemptLifecycleStore::class, EloquentReportExportAttemptLifecycleStore::class],
            [ReportDispatchIntentStore::class, EloquentReportDispatchIntentStore::class],
            [ReportAuditIntentStore::class, EloquentReportAuditIntentStore::class],
            [ReportCompletedArtifactRecoveryStore::class, EloquentReportCompletedArtifactRecoveryStore::class],
            [ReportRunExecutionContextRehydrator::class, LaravelReportRunExecutionContextRehydrator::class],
            [ReportExportExecutionContextRehydrator::class, LaravelReportExportExecutionContextRehydrator::class],
            [ReportAuthorizationSubjectReader::class, EloquentReportAuthorizationSubjectReader::class],
            [ReportHttpAuthorizationTargetResolver::class, LaravelReportHttpAuthorizationTargetResolver::class],
            [CurrentReportAbacEvaluator::class, LaravelCurrentReportAbacEvaluator::class],
            [CurrentReportScopeAuthorizer::class, LaravelCurrentReportScopeAuthorizer::class],
            [CurrentReportExactManyAuthorizer::class, LaravelCurrentReportScopeAuthorizer::class],
            [ReportMaterializationDispatcher::class, \App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportMaterializationDispatcher::class],
            [ReportExportDispatcher::class, \App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportExportDispatcher::class],
            [ReportAuditDispatcher::class, \App\BusinessModules\Core\Reporting\Infrastructure\Audit\LaravelReportAuditDispatcher::class],
            [ReportSnapshotSealVerifier::class, \App\BusinessModules\Core\Reporting\Infrastructure\Security\TrustedReportSnapshotSealVerifier::class],
        ];
    }
}
