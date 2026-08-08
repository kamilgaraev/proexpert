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
use App\BusinessModules\Core\Reporting\Application\Audit\ReportAuditOutboxScheduler;
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
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentPublisher;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentReconciler;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Application\Exports\ReconcileCompletedReportArtifacts;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\CoreReportAuditIntentConsumer;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\DeliverReportAuditIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\PublishReportDispatchIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportDispatchIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportExportExecutionLeasesCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportRunExecutionLeasesCommand;
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
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

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
        self::assertFalse($this->app->bound(ReportDefinitionRegistry::class));
        self::assertFalse($this->app->bound(ReportDefinitionBindingAssembler::class));
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
        self::assertNull(config('reporting_execution.artifacts'));
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

    public function test_boot_forces_the_closed_authorization_graph_and_rejects_bypass_bindings(): void
    {
        $source = file_get_contents((new ReflectionClass(
            ReportingExecutionServiceProvider::class,
        ))->getFileName());
        self::assertIsString($source);
        foreach ([
            'validateArchitectureBindings()',
            'ReportAuthorizationSubjectReader::class => EloquentReportAuthorizationSubjectReader::class',
            '$this->boundConcrete($contract) !== $expected',
            '$this->app->make(LaravelReportScopedResourceAuthorizerRegistry::class)',
            '$this->app->make(ReportHttpAuthorizationOrchestrator::class)',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    public function test_runtime_drivers_are_registered_and_closed_schedules_are_present(): void
    {
        $providerSource = file_get_contents((new ReflectionClass(ReportingExecutionServiceProvider::class))->getFileName());
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($providerSource);
        self::assertIsString($consoleRoutes);
        self::assertStringContainsString('$this->commands([', $providerSource);

        foreach ([
            PublishReportDispatchIntentsCommand::class,
            ReconcileReportDispatchIntentsCommand::class,
            DeliverReportAuditIntentsCommand::class,
            ReconcileReportRunExecutionLeasesCommand::class,
            ReconcileReportExportExecutionLeasesCommand::class,
        ] as $command) {
            self::assertStringContainsString(class_basename($command).'::class', $providerSource);
        }

        foreach ([
            "Schedule::command('reports:dispatch-intents:reconcile')",
            "Schedule::command('reports:audit-intents:deliver')",
            "Schedule::command('reports:runs:reconcile-execution-leases')",
            "Schedule::command('reports:exports:reconcile-execution-leases')",
        ] as $schedule) {
            self::assertStringContainsString($schedule, $consoleRoutes);
        }
    }

    public function test_execution_coordinators_and_listeners_are_registered_once(): void
    {
        foreach ([
            ReportDispatchIntentPublisher::class,
            ReportDispatchIntentReconciler::class,
            ReportAuditOutboxScheduler::class,
            CoreReportAuditIntentConsumer::class,
            ReportRunExecutionWatchdog::class,
            ReportExportExecutionWatchdog::class,
            ReportRunAttemptFinalizer::class,
            ReportExportAttemptFinalizer::class,
            ReconcileCompletedReportArtifacts::class,
            ReportExportRendererRegistry::class,
        ] as $service) {
            self::assertTrue($this->app->bound($service), "Missing singleton {$service}");
        }

        self::assertCount(
            2,
            $this->app->make('events')->getListeners(JobFailed::class),
        );

        foreach ([
            'FinalizeFailedReportRunAttempt.php' => 'MaterializeReportRunJob::class',
            'FinalizeFailedReportExportAttempt.php' => 'GenerateReportExportJob::class',
        ] as $file => $jobClass) {
            $source = file_get_contents(base_path(
                "app/BusinessModules/Core/Reporting/Infrastructure/Listeners/{$file}",
            ));
            self::assertIsString($source);
            self::assertStringContainsString(
                "if (\n"
                ."            \$event->connectionName !== 'redis_reports'\n"
                ."            || \$event->job->getQueue() !== 'reports'\n"
                ."            || \$event->job->resolveName() !== {$jobClass}\n"
                ."        ) {\n"
                ."            return;\n"
                .'        }',
                str_replace("\r\n", "\n", $source),
            );
        }
    }

    public function test_completed_artifact_recovery_uses_the_closed_runtime_configuration(): void
    {
        $provider = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php',
        ));
        $service = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php',
        ));

        self::assertIsString($provider);
        self::assertIsString($service);
        self::assertStringContainsString(
            "\$this->configArray('execution')['lease_seconds']",
            $provider,
        );
        self::assertStringContainsString('private int $leaseSeconds', $service);
        self::assertStringNotContainsString('deleteGraceSeconds', $service);
        self::assertStringNotContainsString('deleteCurrent(', $service);
        self::assertStringNotContainsString('private const LEASE_SECONDS', $service);
        self::assertStringNotContainsString('private const DELETE_GRACE_SECONDS', $service);
    }

    public function test_all_runtime_lease_and_attempt_values_are_injected_from_one_configuration(): void
    {
        $runtime = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Application/Execution/ReportExecutionRuntimeConfiguration.php',
        ));
        $runJob = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php',
        ));
        $export = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php',
        ));
        $auditJob = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Audit/AppendReportAuditEventJob.php',
        ));
        $auditStore = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuditIntentStore.php',
        ));

        foreach ([$runtime, $runJob, $export, $auditJob, $auditStore] as $source) {
            self::assertIsString($source);
        }
        self::assertStringContainsString('ReportExecutionRuntimeConfiguration', $runJob);
        self::assertStringContainsString('ReportExecutionRuntimeConfiguration', $export);
        self::assertStringContainsString('ReportExecutionRuntimeConfiguration', $auditJob);
        self::assertStringContainsString('ReportExecutionRuntimeConfiguration', $auditStore);
        self::assertStringNotContainsString('+960 seconds', $runJob);
        self::assertStringNotContainsString('private const LEASE_SECONDS', $export);
        self::assertStringNotContainsString('+60 seconds', $auditJob);
        self::assertStringNotContainsString('app(', $auditJob);
        self::assertStringContainsString('$auditLeaseSeconds <= 120', $runtime);

        foreach ([
            'Application/Dispatch/ReportDispatchBackoffPolicy.php',
            'Infrastructure/Audit/CoreReportAuditIntentConsumer.php',
            'Infrastructure/Persistence/EloquentReportExportStore.php',
            'Infrastructure/Persistence/EloquentReportCompletedArtifactRecoveryStore.php',
        ] as $relativePath) {
            $consumer = file_get_contents(base_path(
                'app/BusinessModules/Core/Reporting/'.$relativePath,
            ));
            self::assertIsString($consumer);
            self::assertStringNotContainsString(
                'ReportExecutionRuntimeConfiguration::canonical()',
                $consumer,
            );
        }
    }

    public function test_audit_delivery_reclaims_expired_leases_before_dispatching_due_ids(): void
    {
        $source = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Console/DeliverReportAuditIntentsCommand.php',
        ));
        self::assertIsString($source);
        $reclaim = strpos($source, '->reclaimExpired(');
        $dispatch = strpos($source, '->dispatchDue(');
        self::assertIsInt($reclaim);
        self::assertIsInt($dispatch);
        self::assertLessThan($dispatch, $reclaim);
    }

    public function test_runtime_layers_have_no_model_or_service_locator_escape_hatches(): void
    {
        foreach ([
            'Infrastructure/Jobs',
            'Infrastructure/Listeners',
            'Infrastructure/Audit',
            'Application/Execution',
            'Application/Exports',
        ] as $relative) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                base_path("app/BusinessModules/Core/Reporting/{$relative}"),
            ));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                foreach ([
                    'ReportRunRecord',
                    'ReportExportRecord',
                    'ReportDispatchIntentRecord',
                    'ReportAuditIntentRecord',
                    'app(',
                ] as $forbidden) {
                    self::assertStringNotContainsString(
                        $forbidden,
                        $source,
                        "Runtime escape hatch {$forbidden} in {$file->getPathname()}",
                    );
                }
            }
        }
    }

    public function test_audit_dispatch_uses_the_serviced_reports_queue(): void
    {
        $source = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Audit/LaravelReportAuditDispatcher.php',
        ));

        self::assertIsString($source);
        self::assertStringContainsString("->onConnection('redis_reports')", $source);
        self::assertStringContainsString("->onQueue('reports')", $source);
        self::assertStringNotContainsString("->onQueue('reports-audit')", $source);

        $job = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Audit/AppendReportAuditEventJob.php',
        ));
        self::assertIsString($job);
        self::assertStringContainsString("\$this->onQueue('reports')", $job);
        self::assertStringNotContainsString('reports-audit', $job);
    }

    public function test_run_and_export_lifecycle_logs_use_closed_structured_identity(): void
    {
        $runJob = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php',
        ));
        $exportService = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php',
        ));

        self::assertIsString($runJob);
        self::assertIsString($exportService);
        foreach ([
            'report_run_execution_started',
            'report_run_execution_ready',
            "'run_id'",
            "'definition_hash'",
            "'query_hash'",
            "'source_hash'",
            '->runDuration(',
        ] as $token) {
            self::assertStringContainsString($token, $runJob);
        }
        foreach ([
            'report_export_execution_started',
            'report_export_execution_ready',
            "'export_id'",
            "'run_id'",
            "'export_hash'",
            "'definition_hash'",
            "'result_hash'",
            '->exportDuration(',
        ] as $token) {
            self::assertStringContainsString($token, $exportService);
        }
        self::assertStringNotContainsString('->getMessage()', $runJob);
        self::assertStringNotContainsString('->getMessage()', $exportService);
    }

    public function test_registered_console_drivers_use_safe_translations(): void
    {
        $commands = [
            'PublishReportDispatchIntentsCommand.php' => 'publish_dispatch_intents',
            'ReconcileReportDispatchIntentsCommand.php' => 'reconcile_dispatch_intents',
            'ReconcileReportRunExecutionLeasesCommand.php' => 'reconcile_run_execution_leases',
            'ReconcileReportExportExecutionLeasesCommand.php' => 'reconcile_export_execution_leases',
        ];
        $translations = require base_path('lang/ru/reports.php');

        foreach ($commands as $file => $translationKey) {
            $source = file_get_contents(base_path(
                "app/BusinessModules/Core/Reporting/Infrastructure/Console/{$file}",
            ));
            self::assertIsString($source);
            self::assertStringContainsString(
                "trans_message('reports.commands.{$translationKey}')",
                $source,
            );
            self::assertIsString($translations['commands'][$translationKey] ?? null);
            self::assertNotSame('', trim($translations['commands'][$translationKey]));
        }
    }

    #[DataProvider('invalidStoreConfiguration')]
    public function test_boot_rejects_values_outside_the_store_contract(string $key, int $value): void
    {
        config()->set($key, $value);

        $this->expectException(\InvalidArgumentException::class);
        (new ReportingExecutionServiceProvider($this->app))->boot();
    }

    #[DataProvider('invalidClosedConfiguration')]
    public function test_boot_rejects_open_or_malformed_configuration(string $key, mixed $value): void
    {
        config()->set($key, $value);

        $this->expectException(\InvalidArgumentException::class);
        (new ReportingExecutionServiceProvider($this->app))->boot();
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
            [ReportSnapshotSealVerifier::class, \App\BusinessModules\Core\Reporting\Infrastructure\Security\ContentHashReportSnapshotSealVerifier::class],
        ];
    }

    public static function invalidStoreConfiguration(): array
    {
        return [
            'run ttl above maximum' => ['reporting_execution.runs.ttl_seconds', 2_592_001],
            'run poll below minimum' => ['reporting_execution.runs.poll_after_ms', 249],
            'run poll above maximum' => ['reporting_execution.runs.poll_after_ms', 30_001],
            'export ttl above maximum' => ['reporting_execution.exports.ttl_seconds', 2_592_001],
            'export poll below minimum' => ['reporting_execution.exports.poll_after_ms', 249],
            'export poll above maximum' => ['reporting_execution.exports.poll_after_ms', 30_001],
        ];
    }

    public static function invalidClosedConfiguration(): array
    {
        return [
            'run settings missing member' => [
                'reporting_execution.runs',
                ['ttl_seconds' => 86400],
            ],
            'dispatch settings contain extra member' => [
                'reporting_execution.dispatch',
                [
                    'batch_size' => 100,
                    'lease_seconds' => 60,
                    'max_attempts' => 12,
                    'fallback' => 1,
                ],
            ],
            'execution setting is not integer' => [
                'reporting_execution.execution.lease_seconds',
                '960',
            ],
            'reports queue is changed' => [
                'queue.connections.redis_reports.queue',
                'default',
            ],
            'horizon timeout is changed' => [
                'horizon.environments.production.supervisor-reports.timeout',
                900,
            ],
            'alert map contains extra member' => [
                'reporting_execution.alerts',
                [
                    'oldest_pending_seconds' => 300,
                    'audit_dead_letters' => 0,
                    'dispatch_failure_ratio' => 0.05,
                    'lease_reclaims' => 3,
                    'execution_error_ratio' => 0.05,
                    'duration_regression_ratio' => 1.25,
                    'storage_abort_ratio' => 0.01,
                    'fallback' => true,
                ],
            ],
        ];
    }
}
