<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
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
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportScopedResourceAuthorizer;
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
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchBackoffPolicy;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentPublisher;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentReconciler;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Application\Exports\ReconcileCompletedReportArtifacts;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionService;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\CoreReportAuditIntentConsumer;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\LaravelReportAuditDispatcher;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\DeliverReportAuditIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\PublishReportDispatchIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportDispatchIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportExportExecutionLeasesCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportRunExecutionLeasesCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Dispatch\LaravelReportDispatchIntentPublisher;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\DompdfReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\S3ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Listeners\FinalizeFailedReportExportAttempt;
use App\BusinessModules\Core\Reporting\Infrastructure\Listeners\FinalizeFailedReportRunAttempt;
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
use App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\TrustedReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\LaravelReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\ReportExecutionAlertWindow;
use App\Services\Storage\FileService;
use Aws\S3\S3Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class ReportingExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerActions();
        $this->registerInfrastructure();
        $this->registerExecutionServices();
    }

    public function boot(): void
    {
        $this->validatedConfiguration();
        $this->validateArchitectureBindings();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishReportDispatchIntentsCommand::class,
                ReconcileReportDispatchIntentsCommand::class,
                DeliverReportAuditIntentsCommand::class,
                ReconcileReportRunExecutionLeasesCommand::class,
                ReconcileReportExportExecutionLeasesCommand::class,
            ]);
        }

        $events = $this->app->make('events');
        $events->listen(JobFailed::class, FinalizeFailedReportRunAttempt::class);
        $events->listen(JobFailed::class, FinalizeFailedReportExportAttempt::class);
    }

    private function validateArchitectureBindings(): void
    {
        foreach ([
            ReportAuthorizationSubjectReader::class => EloquentReportAuthorizationSubjectReader::class,
            ReportHttpAuthorizationTargetResolver::class => LaravelReportHttpAuthorizationTargetResolver::class,
            ReportModuleEntitlement::class => LaravelReportModuleEntitlement::class,
            CurrentReportAbacEvaluator::class => LaravelCurrentReportAbacEvaluator::class,
        ] as $contract => $expected) {
            if ($this->boundConcrete($contract) !== $expected) {
                throw new InvalidArgumentException(
                    'reporting_execution_architecture_binding_invalid',
                );
            }
        }

        $this->app->make(LaravelReportScopedResourceAuthorizerRegistry::class);
        $this->app->make(CurrentReportScopeAuthorizer::class);
        $this->app->make(ReportExecutionRuntimeConfiguration::class);
        if (
            $this->app->bound('db')
            && $this->app->bound(
                \App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class,
            )
        ) {
            $this->app->make(ReportHttpAuthorizationOrchestrator::class);
        }
    }

    private function boundConcrete(string $contract): ?string
    {
        $binding = $this->app->getBindings()[$contract] ?? null;
        if (! is_array($binding)) {
            return null;
        }
        $concrete = $binding['concrete'] ?? null;
        if (is_string($concrete)) {
            return $concrete;
        }
        if ($concrete instanceof \Closure) {
            $captured = (new \ReflectionFunction($concrete))->getStaticVariables();

            return is_string($captured['concrete'] ?? null)
                ? $captured['concrete']
                : null;
        }

        return null;
    }

    private function registerActions(): void
    {
        $bindings = [
            CreateReportRunAction::class => CreateReportRunHandler::class,
            GetReportRunAction::class => GetReportRunHandler::class,
            RetryReportRunAction::class => RetryReportRunHandler::class,
            CancelReportRunAction::class => CancelReportRunHandler::class,
            GetReportRowsAction::class => GetReportRowsHandler::class,
            GetReportDrillDownAction::class => GetReportDrillDownHandler::class,
            CreateReportExportAction::class => CreateReportExportHandler::class,
            GetReportExportAction::class => GetReportExportHandler::class,
            RetryReportExportAction::class => RetryReportExportHandler::class,
            CancelReportExportAction::class => CancelReportExportHandler::class,
            CreateReportDownloadLinkAction::class => CreateReportDownloadLinkHandler::class,
        ];
        foreach ($bindings as $contract => $handler) {
            $this->app->bind($contract, $handler);
        }
    }

    private function registerInfrastructure(): void
    {
        $this->app->singleton(ReportExecutionClock::class, SystemReportExecutionClock::class);
        $this->app->singleton(ReportExecutionAlertWindow::class);
        $this->app->singleton(ReportExecutionTelemetry::class, function (Container $app): LaravelReportExecutionTelemetry {
            return new LaravelReportExecutionTelemetry(
                $app->make(LoggerInterface::class),
                $this->configArray('alerts'),
                $app->make(ReportExecutionAlertWindow::class),
            );
        });
        $this->app->singleton(ReportAuditIntentStore::class, EloquentReportAuditIntentStore::class);
        $this->app->singleton(ReportTransitionAudit::class, OutboxReportTransitionAudit::class);
        $this->app->singleton(ReportDispatchIntentStore::class, EloquentReportDispatchIntentStore::class);
        $this->app->when(EloquentReportRunStore::class)
            ->needs('$runTtlSeconds')
            ->give(fn (): int => $this->configArray('runs')['ttl_seconds']);
        $this->app->when(EloquentReportRunStore::class)
            ->needs('$pollAfterMs')
            ->give(fn (): int => $this->configArray('runs')['poll_after_ms']);
        $this->app->singleton(ReportRunStore::class, EloquentReportRunStore::class);
        $this->app->when(EloquentReportExportStore::class)
            ->needs('$exportTtlSeconds')
            ->give(fn (): int => $this->configArray('exports')['ttl_seconds']);
        $this->app->when(EloquentReportExportStore::class)
            ->needs('$pollAfterMs')
            ->give(fn (): int => $this->configArray('exports')['poll_after_ms']);
        $this->app->singleton(ReportExportStore::class, EloquentReportExportStore::class);
        $this->app->singleton(ReportReadyDownloadStore::class, static fn (Container $app) => $app->make(ReportExportStore::class));
        $this->app->singleton(ReportRunAsyncContextSeedReader::class, EloquentReportRunAsyncContextSeedReader::class);
        $this->app->singleton(ReportExportAsyncContextSeedReader::class, EloquentReportExportAsyncContextSeedReader::class);
        $this->app->singleton(ReportRunLeaseRecoveryStore::class, EloquentReportRunLeaseRecoveryStore::class);
        $this->app->singleton(ReportExportLeaseRecoveryStore::class, EloquentReportExportLeaseRecoveryStore::class);
        $this->app->singleton(ReportRunAttemptLifecycleStore::class, EloquentReportRunAttemptLifecycleStore::class);
        $this->app->singleton(ReportExportAttemptLifecycleStore::class, EloquentReportExportAttemptLifecycleStore::class);
        $this->app->when(EloquentReportCompletedArtifactRecoveryStore::class)
            ->needs('$pollAfterMs')
            ->give(fn (): int => $this->configArray('exports')['poll_after_ms']);
        $this->app->singleton(ReportCompletedArtifactRecoveryStore::class, EloquentReportCompletedArtifactRecoveryStore::class);

        $this->app->singleton(ReportMaterializationDispatcher::class, LaravelReportMaterializationDispatcher::class);
        $this->app->singleton(ReportExportDispatcher::class, LaravelReportExportDispatcher::class);
        $this->app->singleton(ReportAuditDispatcher::class, LaravelReportAuditDispatcher::class);
        $this->app->singleton(ReportRunExecutionContextRehydrator::class, LaravelReportRunExecutionContextRehydrator::class);
        $this->app->singleton(ReportExportExecutionContextRehydrator::class, LaravelReportExportExecutionContextRehydrator::class);

        $this->app->singleton(ReportAuthorizationSubjectReader::class, EloquentReportAuthorizationSubjectReader::class);
        $this->app->singleton(ReportHttpAuthorizationTargetResolver::class, LaravelReportHttpAuthorizationTargetResolver::class);
        $this->app->singleton(ReportModuleEntitlement::class, LaravelReportModuleEntitlement::class);
        $this->app->singleton(CurrentReportAbacEvaluator::class, LaravelCurrentReportAbacEvaluator::class);
        $this->app->singleton(LaravelReportScopedResourceAuthorizerRegistry::class, function (Container $app): LaravelReportScopedResourceAuthorizerRegistry {
            return new LaravelReportScopedResourceAuthorizerRegistry(
                $app->tagged(ReportScopedResourceAuthorizer::class),
            );
        });
        $this->app->singleton(LaravelCurrentReportScopeAuthorizer::class);
        $this->app->singleton(CurrentReportScopeAuthorizer::class, static fn (Container $app) => $app->make(LaravelCurrentReportScopeAuthorizer::class));
        $this->app->singleton(CurrentReportExactManyAuthorizer::class, static fn (Container $app) => $app->make(LaravelCurrentReportScopeAuthorizer::class));
        $this->app->singleton(ReportHttpAuthorizationOrchestrator::class, function (Container $app): ReportHttpAuthorizationOrchestrator {
            return new ReportHttpAuthorizationOrchestrator(
                $app->make('db')->connection(),
                $app->make(\App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory::class),
                $app->make(ReportHttpAuthorizationTargetResolver::class),
                $app->make(ReportAuthorizationSubjectReader::class),
                $app->make(CurrentReportScopeAuthorizer::class),
            );
        });

        $this->app->singleton(ReportSnapshotSealVerifier::class, function (): TrustedReportSnapshotSealVerifier {
            return new TrustedReportSnapshotSealVerifier($this->configArray('trusted_seal_keys'));
        });
        $this->app->singleton(SignedReportCursorCodec::class, function (Container $app): SignedReportCursorCodec {
            $secret = (string) config('app.key');
            if (str_starts_with($secret, 'base64:')) {
                $decoded = base64_decode(substr($secret, 7), true);
                $secret = is_string($decoded) ? $decoded : '';
            }
            if (strlen($secret) < 32) {
                throw new InvalidArgumentException('report_cursor_keys_invalid');
            }

            return new SignedReportCursorCodec(
                ['application' => hash_hmac('sha256', 'reporting-cursor-v1', $secret, true)],
                'application',
                $app->make(ReportExecutionClock::class),
            );
        });

        $this->registerRenderers();
        $this->registerArtifactInventory();
    }

    private function registerRenderers(): void
    {
        $this->app->singleton(CsvReportExportRenderer::class, static fn () => new CsvReportExportRenderer(ReportExportLimits::csv()));
        $this->app->singleton(XlsxReportExportRenderer::class, static fn () => new XlsxReportExportRenderer(ReportExportLimits::xlsx()));
        $this->app->singleton(ReportPdfDocumentRenderer::class, DompdfReportPdfDocumentRenderer::class);
        $this->app->singleton(ReportPdfDocumentBuilder::class, static fn () => new ReportPdfDocumentBuilder(ReportExportLimits::pdf()));
        $this->app->singleton(PdfReportExportRenderer::class, function (Container $app): PdfReportExportRenderer {
            $budgets = [];
            $configuredBudgets = config('reporting_execution.pdf_budgets');
            if (! is_array($configuredBudgets)) {
                throw new InvalidArgumentException('report_pdf_budget_registry_invalid');
            }
            foreach ($configuredBudgets as $key => $budget) {
                if (! is_string($key) || ! is_array($budget)) {
                    throw new InvalidArgumentException('report_pdf_budget_registry_invalid');
                }
                $budgets[$key] = new ReportPdfRenderBudget(
                    $budget['max_detail_rows'] ?? 0,
                    $budget['max_pages'] ?? 0,
                    $budget['max_html_bytes'] ?? 0,
                    $budget['max_pdf_bytes'] ?? 0,
                    $budget['max_memory_delta_bytes'] ?? 0,
                );
            }

            return new PdfReportExportRenderer(
                $app->make(ReportPdfDocumentBuilder::class),
                $app->make(ReportPdfDocumentRenderer::class),
                $budgets,
            );
        });
        $this->app->singleton(ReportExportRendererRegistry::class);
    }

    private function registerArtifactInventory(): void
    {
        $this->app->singleton(ReportArtifactVersionInventory::class, function (Container $app): S3ReportArtifactVersionInventory {
            $disk = config('filesystems.disks.s3');
            if (! is_array($disk) || ! is_string($disk['bucket'] ?? null) || $disk['bucket'] === '') {
                throw new InvalidArgumentException('report_s3_inventory_configuration_invalid');
            }
            $client = new S3Client([
                'version' => 'latest',
                'region' => $disk['region'] ?? 'ru-central1',
                'endpoint' => $disk['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
                'credentials' => [
                    'key' => $disk['key'] ?? '',
                    'secret' => $disk['secret'] ?? '',
                ],
            ]);

            return new S3ReportArtifactVersionInventory(
                $client,
                $app->make(FileService::class),
                $disk['bucket'],
            );
        });
    }

    private function registerExecutionServices(): void
    {
        $this->app->singleton(ReportExecutionRuntimeConfiguration::class, function (): ReportExecutionRuntimeConfiguration {
            $dispatch = $this->configArray('dispatch');
            $audit = $this->configArray('audit');
            $execution = $this->configArray('execution');

            return new ReportExecutionRuntimeConfiguration(
                $dispatch['batch_size'],
                $dispatch['lease_seconds'],
                $dispatch['max_attempts'],
                $audit['batch_size'],
                $audit['lease_seconds'],
                $audit['max_attempts'],
                $execution['lease_seconds'],
                $execution['watchdog_batch_size'],
            );
        });
        $this->app->when(PublishReportDispatchIntentsCommand::class)
            ->needs('$batchSize')
            ->give(fn (): int => $this->configArray('dispatch')['batch_size']);
        $this->app->when(ReconcileReportDispatchIntentsCommand::class)
            ->needs('$batchSize')
            ->give(fn (): int => $this->configArray('dispatch')['batch_size']);
        $this->app->singleton(LaravelReportDispatchIntentPublisher::class);
        $this->app->singleton(ReportDispatchBackoffPolicy::class);
        $this->app->singleton(ReportDispatchIntentPublisher::class, function (Container $app): ReportDispatchIntentPublisher {
            return new ReportDispatchIntentPublisher(
                $app->make(ReportDispatchIntentStore::class),
                $app->make(LaravelReportDispatchIntentPublisher::class),
                $app->make(ReportDispatchBackoffPolicy::class),
                $app->make(ReportExecutionTelemetry::class),
                $app->make(ReportExecutionRuntimeConfiguration::class),
            );
        });
        $this->app->singleton(ReportDispatchIntentReconciler::class);
        $this->app->singleton(ReportAuditOutboxScheduler::class);
        $this->app->singleton(CoreReportAuditIntentConsumer::class);
        $this->app->singleton(ReportRunExecutionWatchdog::class);
        $this->app->singleton(ReportExportExecutionWatchdog::class);
        $this->app->singleton(ReportRunAttemptFinalizer::class);
        $this->app->singleton(ReportExportAttemptFinalizer::class);
        $this->app->singleton(FinalizeFailedReportRunAttempt::class);
        $this->app->singleton(FinalizeFailedReportExportAttempt::class);
        $this->app->singleton(ReportExportExecutionService::class, function (Container $app): ReportExportExecutionService {
            return new ReportExportExecutionService(
                $app->make(ReportExportAttemptLifecycleStore::class),
                $app->make(ReportExportExecutionContextRehydrator::class),
                $app->make(ReportExportStore::class),
                $app->make(ReportRunStore::class),
                $app->make(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class),
                $app->make(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap::class),
                $app->make(\App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader::class),
                $app->make(ReportExportRendererRegistry::class),
                $app->make(FileService::class),
                $app->make(ReportArtifactVersionInventory::class),
                $app->make(ReportExecutionClock::class),
                $app->make(ReportExecutionTelemetry::class),
                $app->make(ReportAuthorizationSubjectReader::class),
                $app->make(CurrentReportExactManyAuthorizer::class),
                $app->make(\App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory::class),
                $app->make(ReportExecutionRuntimeConfiguration::class),
                $this->configArray('exports')['chunk_size'],
            );
        });
        $this->app->when(ReconcileCompletedReportArtifacts::class)
            ->needs('$leaseSeconds')
            ->give(fn (): int => $this->configArray('execution')['lease_seconds']);
        $this->app->when(ReconcileCompletedReportArtifacts::class)
            ->needs('$deleteGraceSeconds')
            ->give(fn (): int => $this->configArray('artifacts')['reconciliation_grace_seconds']);
        $this->app->singleton(ReconcileCompletedReportArtifacts::class);
    }

    private function validatedConfiguration(): void
    {
        $runs = $this->closedIntegerMap('runs', ['ttl_seconds', 'poll_after_ms']);
        $exports = $this->closedIntegerMap('exports', ['ttl_seconds', 'poll_after_ms', 'chunk_size']);
        $dispatch = $this->closedIntegerMap('dispatch', ['batch_size', 'lease_seconds', 'max_attempts']);
        $audit = $this->closedIntegerMap('audit', ['batch_size', 'lease_seconds', 'max_attempts']);
        $execution = $this->closedIntegerMap('execution', ['lease_seconds', 'watchdog_batch_size']);
        $artifacts = $this->closedIntegerMap('artifacts', ['reconciliation_grace_seconds']);

        if (
            $runs['ttl_seconds'] < 3600 || $runs['ttl_seconds'] > 2_592_000
            || $runs['poll_after_ms'] < 250 || $runs['poll_after_ms'] > 30_000
            || $exports['ttl_seconds'] < 3600 || $exports['ttl_seconds'] > 2_592_000
            || $exports['poll_after_ms'] < 250 || $exports['poll_after_ms'] > 30_000
            || $exports['chunk_size'] < 1 || $exports['chunk_size'] > 5000
            || $dispatch !== ['batch_size' => 100, 'lease_seconds' => 60, 'max_attempts' => 12]
            || $audit !== ['batch_size' => 100, 'lease_seconds' => 300, 'max_attempts' => 12]
            || $execution !== ['lease_seconds' => 960, 'watchdog_batch_size' => 100]
            || $artifacts !== ['reconciliation_grace_seconds' => 3600]
            || config('queue.connections.redis_reports.queue') !== 'reports'
            || config('queue.connections.redis_reports.job_timeout') !== 900
            || config('queue.connections.redis_reports.execution_lease_seconds') !== 960
            || config('queue.connections.redis_reports.retry_after') !== 1200
            || config('horizon.environments.production.supervisor-reports.timeout') !== 960
            || config('horizon.environments.local.supervisor-reports.timeout') !== 960
        ) {
            throw new InvalidArgumentException('reporting_execution_configuration_invalid');
        }

        new TrustedReportSnapshotSealVerifier($this->configArray('trusted_seal_keys'));
        $this->validatedAlerts();
    }

    private function validatedAlerts(): void
    {
        $alerts = $this->configArray('alerts');
        $expected = [
            'oldest_pending_seconds',
            'audit_dead_letters',
            'dispatch_failure_ratio',
            'lease_reclaims',
            'execution_error_ratio',
            'duration_regression_ratio',
            'storage_abort_ratio',
        ];
        $keys = array_keys($alerts);
        if ($keys !== $expected
            || ! is_int($alerts['oldest_pending_seconds'])
            || ! is_int($alerts['audit_dead_letters'])
            || ! is_float($alerts['dispatch_failure_ratio'])
            || ! is_int($alerts['lease_reclaims'])
            || ! is_float($alerts['execution_error_ratio'])
            || ! is_float($alerts['duration_regression_ratio'])
            || ! is_float($alerts['storage_abort_ratio'])
            || $alerts['oldest_pending_seconds'] < 1
            || $alerts['audit_dead_letters'] !== 0
            || $alerts['dispatch_failure_ratio'] <= 0
            || $alerts['dispatch_failure_ratio'] >= 1
            || $alerts['lease_reclaims'] < 1
            || $alerts['execution_error_ratio'] <= 0
            || $alerts['execution_error_ratio'] >= 1
            || $alerts['duration_regression_ratio'] <= 1
            || $alerts['storage_abort_ratio'] <= 0
            || $alerts['storage_abort_ratio'] >= 1
        ) {
            throw new InvalidArgumentException('reporting_execution_alerts_invalid');
        }
    }

    private function closedIntegerMap(string $key, array $expectedKeys): array
    {
        $values = $this->configArray($key);
        if (array_keys($values) !== $expectedKeys) {
            throw new InvalidArgumentException('reporting_execution_configuration_invalid');
        }
        foreach ($values as $value) {
            if (! is_int($value)) {
                throw new InvalidArgumentException('reporting_execution_configuration_invalid');
            }
        }

        return $values;
    }

    private function configArray(string $key): array
    {
        $value = config("reporting_execution.{$key}");
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('reporting_execution_configuration_invalid');
        }

        return $value;
    }
}
