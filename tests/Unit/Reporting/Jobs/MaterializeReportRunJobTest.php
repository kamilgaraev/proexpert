<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Jobs;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportProgressWritePolicy;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotIdentityViolationReason;
use App\BusinessModules\Core\Reporting\Domain\Exceptions\ReportSnapshotIdentityViolation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Job;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class MaterializeReportRunJobTest extends TestCase
{
    public function test_job_payload_and_retry_runtime_are_closed(): void
    {
        $job = new MaterializeReportRunJob('01J00000000000000000000000');
        $reflection = new ReflectionClass($job);

        self::assertSame(['runId'], array_values(array_filter(
            array_keys(get_object_vars($job)),
            static fn (string $property): bool => ! in_array($property, [
                'tries',
                'timeout',
                'failOnTimeout',
                'job',
                'connection',
                'queue',
                'delay',
                'afterCommit',
                'middleware',
                'chained',
                'chainConnection',
                'chainQueue',
                'chainCatchCallbacks',
            ], true),
        )));
        self::assertSame(5, $job->tries);
        self::assertSame(900, $job->timeout);
        self::assertTrue($job->failOnTimeout);
        self::assertSame([30, 120, 300, 900], $job->backoff());
        self::assertFalse($reflection->hasMethod('failed'));
    }

    public function test_cancelled_delivery_stops_before_catalog_or_provider_resolution(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $run = (new ReportRunBuilder)
            ->status(ReportRunStatus::CANCELLED)
            ->queued();
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->expects(self::never())->method('forRun');
        $runs = $this->createMock(ReportRunStore::class);
        $runs->expects(self::never())->method('get');
        $runs->expects(self::never())->method('claimMaterialization');
        $definitions = $this->createMock(ReportDefinitionRegistry::class);
        $definitions->expects(self::never())->method('published');
        $bindings = $this->createMock(ReportDefinitionBindingAssembler::class);
        $bindings->expects(self::never())->method('assemble');
        $job = new MaterializeReportRunJob($run->id);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        $lifecycle = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $lifecycle->expects(self::once())->method('claimOrRenew')->willReturn(false);
        $job->handle(
            $lifecycle,
            $contexts,
            $runs,
            $definitions,
            $bindings,
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->createMock(ReportExecutionTelemetry::class),
        );
    }

    public function test_ready_duplicate_is_fenced_before_current_fact_or_provider_resolution(): void
    {
        $runId = '01J00000000000000000000000';
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->with(
            $runId,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            self::isInstanceOf(DateTimeImmutable::class),
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(false);
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->expects(self::never())->method('forRun');
        $runs = $this->createMock(ReportRunStore::class);
        $runs->expects(self::never())->method('get');
        $definitions = $this->createMock(ReportDefinitionRegistry::class);
        $definitions->expects(self::never())->method('published');
        $bindings = $this->createMock(ReportDefinitionBindingAssembler::class);
        $bindings->expects(self::never())->method('assemble');
        $job = new MaterializeReportRunJob($runId);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        $job->handle(
            $attempts,
            $contexts,
            $runs,
            $definitions,
            $bindings,
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->createMock(ReportExecutionTelemetry::class),
        );
    }

    public function test_malformed_envelope_uuid_fails_before_claim_or_current_fact_loading(): void
    {
        $job = new MaterializeReportRunJob('01J00000000000000000000000');
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('not-a-uuid');
        $job->setJob($envelope);
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::never())->method('claimOrRenew');
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->expects(self::never())->method('forRun');

        $this->expectException(ReportContractException::class);
        $job->handle(
            $attempts,
            $contexts,
            $this->createMock(ReportRunStore::class),
            $this->createMock(ReportDefinitionRegistry::class),
            $this->createMock(ReportDefinitionBindingAssembler::class),
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->createMock(ReportExecutionTelemetry::class),
        );
    }

    public function test_unexpected_current_fact_throwable_is_wrapped_for_retry_and_keeps_live_lease(): void
    {
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->willReturn(true);
        $attempts->expects(self::never())->method('failLeased');
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->method('forRun')->willThrowException(new \RuntimeException('transient'));
        $telemetry = $this->createMock(ReportExecutionTelemetry::class);
        $telemetry->expects(self::once())->method('executionAttempt')->with(
            'run',
            ReportErrorCode::REPORT_INTERNAL_ERROR->value,
        );
        $job = new MaterializeReportRunJob('01J00000000000000000000000');
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        try {
            $job->handle(
                $attempts,
                $contexts,
                $this->createMock(ReportRunStore::class),
                $this->createMock(ReportDefinitionRegistry::class),
                $this->createMock(ReportDefinitionBindingAssembler::class),
                new CanonicalReportSourceHashBuilder,
                new ReportProgressWritePolicy,
                new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
                $telemetry,
            );
            self::fail('Unexpected throwable must escape as a retryable report failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_success_reloads_binding_query_and_uses_one_context_and_snapshot_for_both_provider_stages(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $definition = (new ReportDefinitionBuilder)->payload();
        $query = new ReportQuery($definition, $context->scope, new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-26T09:00:00Z'), 'ru');
        [$snapshot, $result] = $this->sealedPair($context, $query);
        $provider = new class($snapshot, $result) implements ReportDataProvider
        {
            public array $calls = [];

            public function __construct(private ReportSnapshotRef $snapshot, private ReportResult $result) {}

            public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
            {
                $this->calls[] = ['materialize', $context, $query, $progress];
                $progress->advance(50);

                return $this->snapshot;
            }

            public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
            {
                $this->calls[] = ['result', $context, $snapshot];

                return $this->result;
            }
        };
        $binding = new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $provider,
            $this->createMock(ReportRowQuery::class),
            $this->createMock(ReportDrillDownProvider::class),
            null,
        );
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->expects(self::once())->method('published')->with($definition->code)->willReturn((new ReportDefinitionBuilder)->published());
        $assembler = $this->createMock(ReportDefinitionBindingAssembler::class);
        $assembler->expects(self::once())->method('assemble')->with($registry)->willReturn(new ReportDefinitionBindingMap([$definition->code => $binding]));
        $run = (new ReportRunBuilder)->reportCode($definition->code)->status(ReportRunStatus::MATERIALIZING)->queued();
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->method('forRun')->willReturn($context);
        $runs = $this->createMock(ReportRunStore::class);
        $runs->expects(self::once())->method('get')->willReturn($run);
        $runs->expects(self::once())->method('claimMaterialization')->willReturn($run);
        $runs->expects(self::once())->method('queryForRun')->willReturn($query);
        $runs->expects(self::once())->method('persistProgress')->with(
            $context,
            $run->id,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            self::callback(static fn (ReportProgress $progress): bool => $progress->percent() === 50),
            self::isInstanceOf(DateTimeImmutable::class),
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn($run);
        $runs->expects(self::once())->method('sealReady')->with(
            $context,
            $run->id,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            $snapshot,
            $result,
            $snapshot->sourceHash,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn($run);
        $job = new MaterializeReportRunJob($run->id);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        $job->handle(
            $this->lifecycleClaiming($run->id),
            $contexts,
            $runs,
            $registry,
            $assembler,
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->createMock(ReportExecutionTelemetry::class),
        );

        self::assertSame($context, $provider->calls[0][1]);
        self::assertSame($context, $provider->calls[1][1]);
        self::assertSame($snapshot, $provider->calls[1][2]);
    }

    public function test_non_retryable_current_authorization_failure_terminalizes_only_the_live_claim(): void
    {
        $runId = '01J00000000000000000000000';
        $token = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->willReturn(true);
        $attempts->expects(self::once())->method('failLeased')->with(
            $runId,
            $token,
            ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(true);
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->expects(self::once())->method('forRun')->willThrowException(
            ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN),
        );
        $runs = $this->createMock(ReportRunStore::class);
        $runs->expects(self::never())->method('get');
        $definitions = $this->createMock(ReportDefinitionRegistry::class);
        $definitions->expects(self::never())->method('published');
        $job = new MaterializeReportRunJob($runId);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn($token);
        $job->setJob($envelope);

        $job->handle(
            $attempts,
            $contexts,
            $runs,
            $definitions,
            $this->createMock(ReportDefinitionBindingAssembler::class),
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->createMock(ReportExecutionTelemetry::class),
        );
    }

    public function test_binding_version_drift_terminalizes_before_provider_access(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $definition = (new ReportDefinitionBuilder)->payload();
        $query = new ReportQuery(
            $definition,
            $context->scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2026-07-26T09:00:00Z'),
            'ru',
        );
        $provider = $this->createMock(ReportDataProvider::class);
        $provider->expects(self::never())->method('materialize');
        $binding = new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            'drifted-contract',
            $provider,
            $this->createMock(ReportRowQuery::class),
            $this->createMock(ReportDrillDownProvider::class),
            null,
        );
        $run = (new ReportRunBuilder)->reportCode($definition->code)->status(ReportRunStatus::MATERIALIZING)->queued();
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->method('forRun')->willReturn($context);
        $runs = $this->createMock(ReportRunStore::class);
        $runs->expects(self::once())->method('get')->willReturn($run);
        $runs->expects(self::once())->method('claimMaterialization')->willReturn($run);
        $runs->expects(self::once())->method('queryForRun')->willReturn($query);
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->expects(self::once())->method('published')->willReturn((new ReportDefinitionBuilder)->published());
        $assembler = $this->createMock(ReportDefinitionBindingAssembler::class);
        $assembler->expects(self::once())->method('assemble')->willReturn(
            new ReportDefinitionBindingMap([$definition->code => $binding]),
        );
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->willReturn(true);
        $attempts->expects(self::never())->method('failLeased');
        $job = new MaterializeReportRunJob($run->id);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        $this->expectException(ReportContractException::class);
        try {
            $job->handle(
                $attempts,
                $contexts,
                $runs,
                $registry,
                $assembler,
                new CanonicalReportSourceHashBuilder,
                new ReportProgressWritePolicy,
                new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
                $this->createMock(ReportExecutionTelemetry::class),
            );
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
            throw $exception;
        }
    }

    public function test_retryable_current_fact_failure_stays_leased_and_escapes_for_queue_retry(): void
    {
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->willReturn(true);
        $attempts->expects(self::never())->method('failLeased');
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->method('forRun')->willThrowException(
            ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE),
        );
        $telemetry = $this->createMock(ReportExecutionTelemetry::class);
        $telemetry->expects(self::once())->method('executionAttempt')->with('run', ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value);
        $job = new MaterializeReportRunJob('01J00000000000000000000000');
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        $this->expectException(ReportContractException::class);
        $job->handle(
            $attempts,
            $contexts,
            $this->createMock(ReportRunStore::class),
            $this->createMock(ReportDefinitionRegistry::class),
            $this->createMock(ReportDefinitionBindingAssembler::class),
            new CanonicalReportSourceHashBuilder,
            new ReportProgressWritePolicy,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $telemetry,
        );
    }

    public function test_non_retryable_provider_failure_terminalizes_and_emits_failed_transition_only_when_live_lease_wins(): void
    {
        $provider = new class implements ReportDataProvider
        {
            public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
            }

            public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
            {
                throw new \LogicException('unreachable');
            }
        };
        [$job, $arguments, $attempts, $telemetry] = $this->providerRuntime($provider);
        $attempts->expects(self::once())->method('failLeased')->willReturn(true);
        $telemetry->expects(self::once())->method('runTransition')->with(
            'report',
            ReportRunStatus::FAILED->value,
        );

        $job->handle(...$arguments);
    }

    public function test_stale_token_losing_non_retryable_provider_race_emits_no_failed_transition(): void
    {
        $provider = new class implements ReportDataProvider
        {
            public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
            }

            public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
            {
                throw new \LogicException('unreachable');
            }
        };
        [$job, $arguments, $attempts, $telemetry] = $this->providerRuntime($provider);
        $attempts->expects(self::once())->method('failLeased')->willReturn(false);
        $telemetry->expects(self::never())->method('runTransition');

        $job->handle(...$arguments);
    }

    #[DataProvider('structuralProviderFailures')]
    public function test_structural_provider_failure_is_wrapped_for_retry_without_terminalizing(
        \Throwable $failure,
    ): void {
        $provider = new class($failure) implements ReportDataProvider
        {
            public function __construct(private \Throwable $failure) {}

            public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
            {
                throw $this->failure;
            }

            public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
            {
                throw new \LogicException('unreachable');
            }
        };
        [$job, $arguments, $attempts] = $this->providerRuntime($provider);
        $attempts->expects(self::never())->method('failLeased');

        try {
            $job->handle(...$arguments);
            self::fail('Structural provider failure must escape for retry.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
    }

    public static function structuralProviderFailures(): iterable
    {
        yield 'stale seal time' => [
            new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::SEAL_TIME_INVALID),
        ];
        yield 'unknown malformed provider structure' => [
            new \InvalidArgumentException('unknown provider structure'),
        ];
    }

    public function test_progress_write_is_suppressed_inside_time_window_before_retryable_result_failure(): void
    {
        [$job, $arguments, $attempts, , $runs] = $this->providerRuntime(
            function (ReportExecutionContext $context, ReportQuery $query): ReportDataProvider {
                [$snapshot] = $this->sealedPair($context, $query);

                return new class($snapshot) implements ReportDataProvider
                {
                    public function __construct(private ReportSnapshotRef $snapshot) {}

                    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
                    {
                        $progress->advance(1);

                        return $this->snapshot;
                    }

                    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
                    {
                        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
                    }
                };
            },
            new DateTimeImmutable('2026-07-26T10:00:00Z'),
        );
        $runs->expects(self::never())->method('persistProgress');
        $attempts->expects(self::never())->method('failLeased');

        $this->expectException(ReportContractException::class);
        $job->handle(...$arguments);
    }

    public function test_unexpected_result_failure_keeps_live_lease_and_escapes_for_retry(): void
    {
        [$job, $arguments, $attempts] = $this->providerRuntime(
            function (ReportExecutionContext $context, ReportQuery $query): ReportDataProvider {
                [$snapshot] = $this->sealedPair($context, $query);

                return new class($snapshot) implements ReportDataProvider
                {
                    public function __construct(private ReportSnapshotRef $snapshot) {}

                    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
                    {
                        return $this->snapshot;
                    }

                    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
                    {
                        throw new \RuntimeException('result unavailable');
                    }
                };
            },
        );
        $attempts->expects(self::never())->method('failLeased');

        try {
            $job->handle(...$arguments);
            self::fail('Unexpected result failure must escape for retry.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_seal_ready_failure_keeps_live_lease_and_escapes_for_retry(): void
    {
        [$job, $arguments, $attempts, , $runs] = $this->providerRuntime(
            function (ReportExecutionContext $context, ReportQuery $query): ReportDataProvider {
                [$snapshot, $result] = $this->sealedPair($context, $query);

                return new class($snapshot, $result) implements ReportDataProvider
                {
                    public function __construct(
                        private ReportSnapshotRef $snapshot,
                        private ReportResult $result,
                    ) {}

                    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
                    {
                        return $this->snapshot;
                    }

                    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
                    {
                        return $this->result;
                    }
                };
            },
        );
        $runs->method('sealReady')->willThrowException(new \RuntimeException('seal conflict'));
        $attempts->expects(self::never())->method('failLeased');

        try {
            $job->handle(...$arguments);
            self::fail('Seal failure must escape for retry.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    private function providerRuntime(
        ReportDataProvider|\Closure $provider,
        ?DateTimeImmutable $runUpdatedAt = null,
    ): array {
        $context = (new ReportExecutionContextBuilder)->build();
        $definition = (new ReportDefinitionBuilder)->payload();
        $query = new ReportQuery(
            $definition,
            $context->scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2026-07-26T09:00:00Z'),
            'ru',
        );
        if ($provider instanceof \Closure) {
            $provider = $provider($context, $query);
        }
        $run = (new ReportRunBuilder)
            ->reportCode($definition->code)
            ->status(ReportRunStatus::MATERIALIZING)
            ->updatedAt($runUpdatedAt ?? new DateTimeImmutable('2026-01-01T00:01:00Z'))
            ->expiresAt(new DateTimeImmutable('2026-07-27T00:00:00Z'))
            ->queued();
        $binding = new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $provider,
            $this->createMock(ReportRowQuery::class),
            $this->createMock(ReportDrillDownProvider::class),
            null,
        );
        $contexts = $this->createMock(ReportRunExecutionContextRehydrator::class);
        $contexts->method('forRun')->willReturn($context);
        $runs = $this->createMock(ReportRunStore::class);
        $runs->method('get')->willReturn($run);
        $runs->method('claimMaterialization')->willReturn($run);
        $runs->method('queryForRun')->willReturn($query);
        $runs->method('sealReady')->willReturn($run);
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->method('published')->willReturn((new ReportDefinitionBuilder)->published());
        $assembler = $this->createMock(ReportDefinitionBindingAssembler::class);
        $assembler->method('assemble')->willReturn(
            new ReportDefinitionBindingMap([$definition->code => $binding]),
        );
        $attempts = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $attempts->method('claimOrRenew')->willReturn(true);
        $telemetry = $this->createMock(ReportExecutionTelemetry::class);
        $job = new MaterializeReportRunJob($run->id);
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->setJob($envelope);

        return [
            $job,
            [
                $attempts,
                $contexts,
                $runs,
                $registry,
                $assembler,
                new CanonicalReportSourceHashBuilder,
                new ReportProgressWritePolicy,
                new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
                $telemetry,
            ],
            $attempts,
            $telemetry,
            $runs,
        ];
    }

    private function lifecycleClaiming(string $runId): ReportRunAttemptLifecycleStore
    {
        $store = $this->createMock(ReportRunAttemptLifecycleStore::class);
        $store->expects(self::once())->method('claimOrRenew')->with(
            $runId,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            self::isInstanceOf(DateTimeImmutable::class),
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(true);

        return $store;
    }

    #[DataProvider('identityViolationMappings')]
    public function test_only_missing_official_seal_has_the_public_unsealed_error(
        ReportSnapshotIdentityViolationReason $reason,
        ReportErrorCode $expected,
    ): void {
        $method = new ReflectionMethod(MaterializeReportRunJob::class, 'mapIdentityViolation');
        $job = new MaterializeReportRunJob('01J00000000000000000000000');

        $mapped = $method->invoke($job, new ReportSnapshotIdentityViolation($reason));

        self::assertInstanceOf(ReportContractException::class, $mapped);
        self::assertSame($expected, $mapped->errorCode);
    }

    public static function identityViolationMappings(): iterable
    {
        yield 'missing official seal' => [ReportSnapshotIdentityViolationReason::OFFICIAL_SEAL_REQUIRED, ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED];
        yield 'invalid kind' => [ReportSnapshotIdentityViolationReason::INVALID_KIND, ReportErrorCode::REPORT_INTERNAL_ERROR];
        yield 'invalid id' => [ReportSnapshotIdentityViolationReason::INVALID_ID, ReportErrorCode::REPORT_INTERNAL_ERROR];
        yield 'operational seal' => [ReportSnapshotIdentityViolationReason::OPERATIONAL_SEAL_FORBIDDEN, ReportErrorCode::REPORT_INTERNAL_ERROR];
        yield 'invalid seal time' => [ReportSnapshotIdentityViolationReason::SEAL_TIME_INVALID, ReportErrorCode::REPORT_INTERNAL_ERROR];
    }

    private function sealedPair(ReportExecutionContext $context, ReportQuery $query): array
    {
        $generatedAt = new DateTimeImmutable('2026-07-26T09:30:00Z');
        $placeholder = new Sha256Hash(str_repeat('c', 64));
        $snapshot = new ReportSnapshotRef(
            'report',
            'snapshot',
            $context->scope,
            $query->definition->definitionHash,
            $query->definition->formulaVersion,
            $placeholder,
            $generatedAt,
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $result = $this->resultFor($snapshot, $placeholder);
        $sourceHash = (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result);
        $snapshot = new ReportSnapshotRef(
            'report',
            'snapshot',
            $context->scope,
            $query->definition->definitionHash,
            $query->definition->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );

        return [$snapshot, $this->resultFor($snapshot, $sourceHash)];
    }

    private function resultFor(ReportSnapshotRef $snapshot, Sha256Hash $sourceHash): ReportResult
    {
        return new ReportResult(
            new ReportResultMetadata($snapshot, 1, $snapshot->generatedAt, null),
            ['amount' => 1],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []),
            new ReportProvenance(
                'system',
                [new ReportSourceRef('system', 'report', 'snapshot', 'v1', 'watermark', 1, new Sha256Hash(str_repeat('d', 64)))],
                $sourceHash,
                null,
            ),
            [['id' => 'amount']],
            [],
        );
    }
}
