<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportDispatchIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\Support\Reporting\ReportRuntimeFixture;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportRunLeaseRecoveryStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Organization::factory()->create(['id' => 7]);
    }

    public function test_due_and_requeue_are_token_status_expiry_fenced_and_atomic(): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($occurredAt->modify('-1 microsecond'));
        $intents = new RecordingRunDispatchIntentStore;
        $store = new EloquentReportRunLeaseRecoveryStore($intents);

        $leases = $store->due(10, $occurredAt);

        self::assertCount(1, $leases);
        self::assertTrue($store->requeue($leases[0], $occurredAt));
        self::assertFalse($store->requeue($leases[0], $occurredAt));
        self::assertSame('queued', ReportRunRecord::query()->findOrFail($leases[0]->aggregateId)->status);
        self::assertCount(1, $intents->runIntents);

        $lifecycle = new EloquentReportRunAttemptLifecycleStore;
        self::assertFalse($lifecycle->failLeased(
            $leases[0]->aggregateId,
            $leases[0]->expectedLeaseToken,
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $occurredAt,
        ));
        $newToken = '0195e44b-a9e7-7f12-a8af-51f2d284d300';
        self::assertTrue($lifecycle->claimOrRenew(
            $leases[0]->aggregateId,
            $newToken,
            $occurredAt->modify('+960 seconds'),
            $occurredAt,
        ));
        self::assertFalse($lifecycle->failLeased(
            $leases[0]->aggregateId,
            $leases[0]->expectedLeaseToken,
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $occurredAt->modify('+1 second'),
        ));
        self::assertSame($newToken, ReportRunRecord::query()->findOrFail($leases[0]->aggregateId)->execution_lease_token);
    }

    public function test_recovery_intent_failure_rolls_back_the_run_reset(): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($occurredAt->modify('-1 microsecond'));
        $store = new EloquentReportRunLeaseRecoveryStore(new ThrowingRunDispatchIntentStore);
        $lease = $store->due(1, $occurredAt)[0];

        try {
            $store->requeue($lease, $occurredAt);
            self::fail('Expected atomic intent failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('intent_failed', $exception->getMessage());
        }

        $run = ReportRunRecord::query()->findOrFail($lease->aggregateId);
        self::assertSame('materializing', $run->status);
        self::assertSame($lease->expectedLeaseToken, $run->execution_lease_token);
    }

    public function test_recovery_identity_is_generation_safe_at_the_same_expiry_and_replay_is_idempotent(): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $expiry = $occurredAt->modify('-1 microsecond');
        $this->insertMaterializingRun($expiry);
        $store = new EloquentReportRunLeaseRecoveryStore(new EloquentReportDispatchIntentStore(
            $this->createMock(ReportTransitionAudit::class),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        ));

        $oldLease = $store->due(1, $occurredAt)[0];
        self::assertTrue($store->requeue($oldLease, $occurredAt));
        self::assertFalse($store->requeue($oldLease, $occurredAt));
        ReportDispatchIntentRecord::query()->update([
            'status' => 'published',
            'published_at' => $occurredAt,
        ]);

        $newToken = '0195e44b-a9e7-7f12-a8af-51f2d284d300';
        ReportRunRecord::query()->whereKey($oldLease->aggregateId)->update([
            'status' => 'materializing',
            'execution_lease_token' => $newToken,
            'execution_lease_expires_at' => $expiry,
            'execution_heartbeat_at' => $expiry->modify('-1 minute'),
        ]);
        $newLease = $store->due(1, $occurredAt)[0];

        self::assertTrue($store->requeue($newLease, $occurredAt));
        self::assertFalse($store->requeue($newLease, $occurredAt));
        self::assertSame(2, ReportDispatchIntentRecord::query()->count());
        $expectedKeys = [
            "reports:run:{$oldLease->aggregateId}:materialize:recovery:{$oldLease->expectedLeaseToken}",
            "reports:run:{$newLease->aggregateId}:materialize:recovery:{$newLease->expectedLeaseToken}",
        ];
        sort($expectedKeys);
        self::assertSame(
            $expectedKeys,
            ReportDispatchIntentRecord::query()->pluck('event_key')->sort()->values()->all(),
        );
    }

    public function test_renewal_wins_row_lock_before_watchdog_and_stale_expired_token_cannot_requeue(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($at);
        $recovery = new EloquentReportRunLeaseRecoveryStore(new RecordingRunDispatchIntentStore);
        $candidate = $recovery->due(1, $at)[0];
        $lifecycle = new EloquentReportRunAttemptLifecycleStore;

        $this->race(
            1,
            function () use ($lifecycle, $candidate, $at): void {
                self::assertTrue($lifecycle->claimOrRenew(
                    $candidate->aggregateId,
                    $candidate->expectedLeaseToken,
                    $at->modify('+15 minutes'),
                    $at->modify('-1 microsecond'),
                ));
            },
            static function () use ($recovery, $at): array {
                $summary = (new ReportRunExecutionWatchdog($recovery, self::telemetry()))->reclaim(1, $at);

                return ['requeued' => $summary->requeued, 'skipped' => $summary->skipped];
            },
            ['requeued' => 0, 'skipped' => 1],
        );

        $run = ReportRunRecord::query()->findOrFail($candidate->aggregateId);
        self::assertSame('materializing', $run->status);
        self::assertSame($candidate->expectedLeaseToken, $run->execution_lease_token);
        self::assertSame(0, ReportDispatchIntentRecord::query()->count());
    }

    public function test_watchdog_wins_row_lock_before_renewal_and_old_token_cannot_renew_requeued_run(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($at);
        $recovery = new EloquentReportRunLeaseRecoveryStore(new EloquentReportDispatchIntentStore(
            $this->createMock(ReportTransitionAudit::class),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        ));
        $lifecycle = new EloquentReportRunAttemptLifecycleStore;
        $runId = '01J00000000000000000000001';
        $token = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';

        $this->race(
            2,
            function () use ($recovery, $at): void {
                $summary = (new ReportRunExecutionWatchdog($recovery, self::telemetry()))->reclaim(1, $at);
                self::assertSame(1, $summary->requeued);
            },
            static fn (): array => ['renewed' => $lifecycle->claimOrRenew(
                $runId,
                $token,
                $at->modify('+15 minutes'),
                $at->modify('-1 microsecond'),
            )],
            ['renewed' => false],
        );

        self::assertSame('queued', ReportRunRecord::query()->findOrFail($runId)->status);
        self::assertSame(1, ReportDispatchIntentRecord::query()->count());
    }

    public function test_finalizer_wins_row_lock_before_recovery_and_terminal_token_is_fenced(): void
    {
        $expiry = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($expiry);
        $recovery = new EloquentReportRunLeaseRecoveryStore(new RecordingRunDispatchIntentStore);
        $candidate = $recovery->due(1, $expiry)[0];
        $finalizer = new ReportRunAttemptFinalizer(new EloquentReportRunAttemptLifecycleStore);

        $this->race(
            3,
            function () use ($finalizer, $candidate, $expiry): void {
                self::assertTrue($finalizer->finalize(
                    $candidate->aggregateId,
                    $candidate->expectedLeaseToken,
                    new RuntimeException('worker_failed'),
                    $expiry->modify('-1 microsecond'),
                ));
            },
            static fn (): array => ['requeued' => $recovery->requeue($candidate, $expiry)],
            ['requeued' => false],
        );

        self::assertSame('failed', ReportRunRecord::query()->findOrFail($candidate->aggregateId)->status);
        self::assertSame(1, ReportAuditIntentRecord::query()->where('event_type', 'report.run.failed')->count());
        self::assertSame(0, ReportDispatchIntentRecord::query()->count());
    }

    public function test_recovery_wins_row_lock_before_finalizer_and_old_token_cannot_terminalize_new_generation(): void
    {
        $expiry = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertMaterializingRun($expiry);
        $recovery = new EloquentReportRunLeaseRecoveryStore(new EloquentReportDispatchIntentStore(
            $this->createMock(ReportTransitionAudit::class),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        ));
        $candidate = $recovery->due(1, $expiry)[0];
        $lifecycle = new EloquentReportRunAttemptLifecycleStore;
        $finalizer = new ReportRunAttemptFinalizer($lifecycle);
        $newToken = '0195e44b-a9e7-7f12-a8af-51f2d284d300';

        $this->race(
            4,
            function () use ($recovery, $lifecycle, $candidate, $expiry, $newToken): void {
                self::assertTrue($recovery->requeue($candidate, $expiry));
                self::assertTrue($lifecycle->claimOrRenew(
                    $candidate->aggregateId,
                    $newToken,
                    $expiry->modify('+15 minutes'),
                    $expiry->modify('+1 microsecond'),
                ));
            },
            static fn (): array => ['finalized' => $finalizer->finalize(
                $candidate->aggregateId,
                $candidate->expectedLeaseToken,
                new RuntimeException('late_worker_failed'),
                $expiry->modify('-1 microsecond'),
            )],
            ['finalized' => false],
        );

        $run = ReportRunRecord::query()->findOrFail($candidate->aggregateId);
        self::assertSame('materializing', $run->status);
        self::assertSame($newToken, $run->execution_lease_token);
        self::assertSame(0, ReportAuditIntentRecord::query()->where('event_type', 'report.run.failed')->count());
        self::assertSame(1, ReportAuditIntentRecord::query()->where('event_type', 'report.run.materializing')->count());
        self::assertSame(1, ReportDispatchIntentRecord::query()->count());
    }

    private function race(
        int $index,
        callable $winner,
        callable $loser,
        array $expectedResult,
    ): void {
        $suffix = bin2hex(random_bytes(6));
        $connectionName = "report_execution_race_{$suffix}";
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-execution-race-{$suffix}",
        );
        $observer = $harness->independentConnection($connectionName);
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            self::assertNotNull(ReportRunRecord::query()->whereKey('01J00000000000000000000001')->lockForUpdate()->first());
            $winner();

            $children[] = $harness->spawn($index, $loser);
            $harness->release($index);
            $workerPid = $harness->waitForWorkerBackendPid($index);
            $harness->waitForPostgresWait($observer, $workerPid);

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame($expectedResult, $harness->result($index));
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            DB::purge($connectionName);
            $harness->cleanup();
        }
    }

    private static function telemetry(): ReportExecutionTelemetry
    {
        return new class implements ReportExecutionTelemetry
        {
            public function runTransition(string $reportCode, string $status): void {}

            public function runDuration(string $reportCode, string $status, float $seconds): void {}

            public function exportTransition(string $reportCode, string $format, string $status): void {}

            public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void {}

            public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void {}

            public function multipartAbort(string $reportCode, string $format): void {}

            public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void {}

            public function executionAttempt(string $intentType, string $errorCode): void {}

            public function executionLeaseReclaimed(string $aggregateKind): void {}

            public function auditDeliveryFailure(string $errorCode, string $outcome): void {}
        };
    }

    private function insertMaterializingRun(DateTimeImmutable $expiresAt): void
    {
        $now = $expiresAt->modify('-10 minutes');
        ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000001',
            'organization_id' => 7,
            'requester_actor_id' => 17,
            'report_code' => 'cost_control',
            'status' => 'materializing',
            'definition_hash' => str_repeat('a', 64),
            'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64),
            'idempotency_key_hash' => str_repeat('d', 64),
            'input_fingerprint' => str_repeat('e', 64),
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'definition_snapshot' => [],
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => [7],
            'scope_project_ids' => [],
            'scope_resources' => [],
            'scope_timezone' => 'UTC',
            'filters' => [],
            'comparison' => [],
            'as_of' => $now,
            'locale' => 'ru',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => [],
            'audit_column_ids' => [],
            'progress' => 10,
            'totals' => [],
            'execution_lease_token' => '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            'execution_lease_expires_at' => $expiresAt,
            'execution_heartbeat_at' => $now,
            'queued_at' => $now,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }
}

class RecordingRunDispatchIntentStore implements ReportDispatchIntentStore
{
    public array $runIntents = [];

    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void
    {
        $this->runIntents[] = func_get_args();
    }

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
    {
        return [];
    }

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void {}

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
    {
        return 0;
    }
}

final class ThrowingRunDispatchIntentStore extends RecordingRunDispatchIntentStore
{
    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void
    {
        throw new RuntimeException('intent_failed');
    }
}
