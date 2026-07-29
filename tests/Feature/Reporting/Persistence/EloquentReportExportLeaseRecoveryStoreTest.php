<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionWatchdog;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\Models\Organization;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportExportLeaseRecoveryStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_due_and_requeue_are_status_token_expiry_fenced_and_atomic(): void
    {
        Organization::factory()->create(['id' => 7]);
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertRunAndExport($at);
        $intents = new RecordingExportDispatchIntentStore;
        $store = new EloquentReportExportLeaseRecoveryStore($intents);

        $leases = $store->due(10, $at);

        self::assertCount(1, $leases);
        self::assertTrue($store->requeue($leases[0], $at));
        self::assertFalse($store->requeue($leases[0], $at));
        $record = ReportExportRecord::query()->findOrFail($leases[0]->aggregateId);
        self::assertSame('queued', $record->status);
        self::assertNull($record->execution_lease_token);
        self::assertCount(1, $intents->exports);
        self::assertSame(
            "reports:export:{$record->id}:generate:recovery:"
                .$leases[0]->expectedLeaseToken,
            $intents->exports[0][2],
        );
    }

    public function test_completed_artifact_recovery_and_watchdog_have_one_winner_across_two_processes(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $newToken = '0195e44b-a9e7-7f12-a8af-51f2d284d300';
        $context = self::recoveryContext();

        $this->race(
            'completed-artifact-watchdog',
            'uploading',
            $at,
            static function () use ($context, $newToken, $at): array {
                $store = new EloquentReportCompletedArtifactRecoveryStore(
                    new ReportExportHydrator,
                );

                try {
                    $claimed = $store->claimExpiredUpload(
                        $context,
                        '01J00000000000000000000001',
                        $newToken,
                        $at->modify('+960 seconds'),
                        $at,
                    );

                    return [
                        'claimed' => true,
                        'status' => $claimed->status->value,
                    ];
                } catch (ReportContractException $exception) {
                    return [
                        'claimed' => false,
                        'error_code' => $exception->errorCode->value,
                    ];
                }
            },
            static fn (): array => self::watchdogResult($at),
            static function (
                ConnectionInterface $observer,
                array $recoveryResult,
                array $watchdogResult,
            ) use ($newToken): void {
                $recoveryWon = $recoveryResult['claimed'] === true;
                $watchdogWon = $watchdogResult['requeued'] === 1;
                self::assertSame(1, (int) $recoveryWon + (int) $watchdogWon);
                self::assertSame(1, $watchdogResult['scanned']);
                self::assertSame(0, $watchdogResult['failed']);

                $record = $observer->table('report_exports')
                    ->where('id', '01J00000000000000000000001')
                    ->first();
                self::assertNotNull($record);
                self::assertNull($record->artifact_path);
                self::assertSame(0, $observer->table('report_audit_intents')->count());

                if ($recoveryWon) {
                    self::assertSame('uploading', $recoveryResult['status']);
                    self::assertSame('uploading', $record->status);
                    self::assertSame($newToken, $record->execution_lease_token);
                    self::assertSame(0, $watchdogResult['requeued']);
                    self::assertSame(1, $watchdogResult['skipped']);
                    self::assertSame(0, $observer->table('report_dispatch_intents')->count());

                    return;
                }

                self::assertSame(
                    ReportErrorCode::REPORT_EXPORT_NOT_READY->value,
                    $recoveryResult['error_code'],
                );
                self::assertSame('queued', $record->status);
                self::assertNull($record->execution_lease_token);
                self::assertSame(1, $watchdogResult['requeued']);
                self::assertSame(0, $watchdogResult['skipped']);
                self::assertSame(1, $observer->table('report_dispatch_intents')->count());
            },
        );
    }

    public function test_terminalization_and_watchdog_have_one_winner_across_two_processes(): void
    {
        $watchdogAt = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $terminalAt = $watchdogAt->modify('-1 microsecond');
        $token = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';

        $this->race(
            'terminalization-watchdog',
            'running',
            $watchdogAt,
            static fn (): array => [
                'terminalized' => (new EloquentReportExportAttemptLifecycleStore)
                    ->failLeased(
                        '01J00000000000000000000001',
                        $token,
                        ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                        $terminalAt,
                    ),
            ],
            static fn (): array => self::watchdogResult($watchdogAt),
            static function (
                ConnectionInterface $observer,
                array $terminalResult,
                array $watchdogResult,
            ): void {
                $terminalWon = $terminalResult['terminalized'] === true;
                $watchdogWon = $watchdogResult['requeued'] === 1;
                self::assertSame(1, (int) $terminalWon + (int) $watchdogWon);
                self::assertSame(1, $watchdogResult['scanned']);
                self::assertSame(0, $watchdogResult['failed']);

                $record = $observer->table('report_exports')
                    ->where('id', '01J00000000000000000000001')
                    ->first();
                self::assertNotNull($record);
                self::assertNull($record->execution_lease_token);

                if ($terminalWon) {
                    self::assertSame('failed', $record->status);
                    self::assertSame(
                        ReportErrorCode::REPORT_DEPENDENCY_FAILED->value,
                        $record->error_code,
                    );
                    self::assertSame(1, $watchdogResult['skipped']);
                    self::assertSame(0, $observer->table('report_dispatch_intents')->count());
                    self::assertSame(
                        1,
                        $observer->table('report_audit_intents')
                            ->where('event_type', 'report.export.failed')
                            ->count(),
                    );

                    return;
                }

                self::assertSame('queued', $record->status);
                self::assertNull($record->error_code);
                self::assertSame(0, $watchdogResult['skipped']);
                self::assertSame(1, $observer->table('report_dispatch_intents')->count());
                self::assertSame(0, $observer->table('report_audit_intents')->count());
            },
        );
    }

    public function test_simultaneous_watchdog_workers_requeue_once_and_loser_has_no_side_effects(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');

        $this->race(
            'simultaneous-watchdogs',
            'running',
            $at,
            static fn (): array => self::watchdogResult($at),
            static fn (): array => self::watchdogResult($at),
            static function (
                ConnectionInterface $observer,
                array $first,
                array $second,
            ): void {
                self::assertSame(2, $first['scanned'] + $second['scanned']);
                self::assertSame(1, $first['requeued'] + $second['requeued']);
                self::assertSame(1, $first['skipped'] + $second['skipped']);
                self::assertSame(0, $first['failed'] + $second['failed']);

                $record = $observer->table('report_exports')
                    ->where('id', '01J00000000000000000000001')
                    ->first();
                self::assertNotNull($record);
                self::assertSame('queued', $record->status);
                self::assertNull($record->execution_lease_token);
                self::assertSame(1, $observer->table('report_dispatch_intents')->count());
                self::assertSame(0, $observer->table('report_audit_intents')->count());
            },
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $firstWorker
     * @param  callable(): array<string, mixed>  $secondWorker
     * @param  callable(ConnectionInterface, array<string, mixed>, array<string, mixed>): void  $assertions
     */
    private function race(
        string $case,
        string $status,
        DateTimeImmutable $leaseExpiresAt,
        callable $firstWorker,
        callable $secondWorker,
        callable $assertions,
    ): void {
        $suffix = bin2hex(random_bytes(6));
        $connectionName = "report_export_race_{$suffix}";
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR
                ."report-export-{$case}-race-{$suffix}",
        );
        $observer = $harness->independentConnection($connectionName);
        $children = [];
        $transactionOpen = false;

        try {
            $this->seedCommittedFixture(
                $observer,
                $status,
                $leaseExpiresAt,
            );
            $observer->beginTransaction();
            $transactionOpen = true;
            self::assertNotNull(
                $observer->table('report_exports')
                    ->where('id', '01J00000000000000000000001')
                    ->lockForUpdate()
                    ->first(),
            );

            $children[] = $harness->spawn(1, $firstWorker);
            $children[] = $harness->spawn(2, $secondWorker);
            $harness->release(1);
            $harness->release(2);
            $firstPid = $harness->waitForWorkerBackendPid(1);
            $secondPid = $harness->waitForWorkerBackendPid(2);
            $harness->waitForPostgresWait($observer, $firstPid);
            $harness->waitForPostgresWait($observer, $secondPid);

            $observer->commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];
            $assertions(
                $observer,
                $harness->result(1),
                $harness->result(2),
            );
        } finally {
            if ($transactionOpen) {
                $observer->rollBack();
            }
            $harness->terminateAndReap($children);
            $this->cleanupCommittedFixture($observer);
            DB::purge($connectionName);
            $harness->cleanup();
        }
    }

    private function seedCommittedFixture(
        ConnectionInterface $connection,
        string $status,
        DateTimeImmutable $leaseExpiresAt,
    ): void {
        $createdAt = $leaseExpiresAt->modify('-20 minutes');
        $connection->table('organizations')->insert([
            'id' => 7,
            'name' => 'Reporting race organization',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $fixture = new EloquentReportExportExecutionFixture;
        $connection->table('report_runs')->insert($fixture->run($createdAt));
        $connection->table('report_exports')->insert($fixture->export(
            $status,
            $createdAt,
            $leaseExpiresAt,
            $leaseExpiresAt->modify('-961 seconds'),
        ));
    }

    private function cleanupCommittedFixture(ConnectionInterface $connection): void
    {
        $connection->table('report_audit_intents')
            ->where('organization_id', 7)
            ->delete();
        $connection->table('report_dispatch_intents')
            ->where('organization_id', 7)
            ->delete();
        $connection->table('report_exports')
            ->where('organization_id', 7)
            ->delete();
        $connection->table('report_runs')
            ->where('organization_id', 7)
            ->delete();
        $connection->table('organizations')->where('id', 7)->delete();
    }

    /** @return array{scanned: int, requeued: int, skipped: int, failed: int} */
    private static function watchdogResult(DateTimeImmutable $occurredAt): array
    {
        $summary = (new ReportExportExecutionWatchdog(
            new EloquentReportExportLeaseRecoveryStore(
                self::durableDispatchIntents(),
            ),
            self::telemetry(),
        ))->reclaim(1, $occurredAt);

        return [
            'scanned' => $summary->scanned,
            'requeued' => $summary->requeued,
            'skipped' => $summary->skipped,
            'failed' => $summary->failed,
        ];
    }

    private static function durableDispatchIntents(): EloquentReportDispatchIntentStore
    {
        return new EloquentReportDispatchIntentStore(
            new class implements ReportTransitionAudit
            {
                public function append(
                    string $eventId,
                    string $eventType,
                    ReportExecutionContext $context,
                    array $subject,
                    DateTimeImmutable $occurredAt,
                ): void {}
            },
        );
    }

    private static function recoveryContext(): ReportExecutionContext
    {
        $scope = new ReportScope(7, [7], [], [], new DateTimeZone('UTC'));
        $base = (new ReportExecutionContextBuilder)->build();

        return (new ReportExecutionContextBuilder)
            ->actor($base->actor)
            ->scope($scope)
            ->authorization(new AuthorizationDecisionContext(
                'queue',
                7,
                [7],
                [],
                [],
                new DateTimeZone('UTC'),
                'reconcile-export',
                null,
            ))
            ->build();
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

    private function insertRunAndExport(DateTimeImmutable $at): void
    {
        $fixture = new EloquentReportExportExecutionFixture;
        DB::table('report_runs')->insert($fixture->run($at->modify('-20 minutes')));
        DB::table('report_exports')->insert($fixture->export(
            'running',
            $at->modify('-20 minutes'),
            $at->modify('-1 microsecond'),
            $at->modify('-961 seconds'),
        ));
    }
}

final class RecordingExportDispatchIntentStore implements ReportDispatchIntentStore
{
    public array $exports = [];

    public function addRunIntent(
        string $runId,
        int $organizationId,
        string $eventKey,
        DateTimeImmutable $occurredAt,
    ): void {}

    public function addExportIntent(
        string $exportId,
        int $organizationId,
        string $eventKey,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->exports[] = [$exportId, $organizationId, $eventKey, $occurredAt];
    }

    public function claimDue(
        int $limit,
        DateTimeImmutable $now,
        DateTimeImmutable $leasedUntil,
        string $leaseToken,
    ): array {
        return [];
    }

    public function markPublished(
        string $intentId,
        string $leaseToken,
        DateTimeImmutable $occurredAt,
    ): void {}

    public function markPublicationFailed(
        string $intentId,
        string $leaseToken,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $nextAttemptAt,
    ): void {}

    public function reclaimExpiredLeases(
        int $limit,
        DateTimeImmutable $occurredAt,
    ): int {
        return 0;
    }
}

final class EloquentReportExportExecutionFixture
{
    /** @return array<string, mixed> */
    public function run(DateTimeImmutable $now): array
    {
        return [
            'id' => '01J00000000000000000000000', 'organization_id' => 7,
            'requester_actor_id' => 17, 'report_code' => 'cost_control',
            'status' => 'queued', 'definition_hash' => str_repeat('a', 64),
            'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64),
            'idempotency_key_hash' => str_repeat('d', 64),
            'input_fingerprint' => str_repeat('e', 64), 'contract_version' => '1',
            'formula_version' => '1', 'source_schema_version' => '1',
            'renderer_version' => '1', 'definition_snapshot' => '{}',
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => '[7]', 'scope_project_ids' => '[]',
            'scope_resources' => '[]', 'scope_timezone' => 'UTC', 'filters' => '[]',
            'comparison' => '[]', 'as_of' => $now, 'locale' => 'ru',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard', 'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]', 'progress' => 0, 'totals' => '[]',
            'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            'expires_at' => $now->modify('+2 hours'),
        ];
    }

    /** @return array<string, mixed> */
    public function export(
        string $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $leaseExpiry,
        DateTimeImmutable $heartbeat,
    ): array {
        return [
            'id' => '01J00000000000000000000001',
            'run_id' => '01J00000000000000000000000', 'organization_id' => 7,
            'requester_actor_id' => 17, 'report_code' => 'cost_control',
            'status' => $status, 'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('c', 64), 'source_hash' => str_repeat('1', 64),
            'result_hash' => str_repeat('2', 64), 'export_hash' => str_repeat('3', 64),
            'idempotency_key_hash' => str_repeat('4', 64),
            'input_fingerprint' => str_repeat('5', 64),
            'scope_holding_organization_ids' => '[7]', 'scope_project_ids' => '[]',
            'scope_resources' => '[]', 'scope_timezone' => 'UTC',
            'snapshot_kind' => 'report', 'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => $createdAt, 'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard', 'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]', 'totals_sensitive' => false,
            'totals_audit' => false, 'provenance_audit' => false,
            'contract_version' => '1', 'formula_version' => '1',
            'source_schema_version' => '1', 'renderer_version' => '1',
            'format' => 'csv', 'selected_columns' => '["name"]',
            'sort_field' => 'name', 'sort_direction' => 'asc', 'locale' => 'ru',
            'render_timezone' => 'UTC',
            'execution_lease_token' => '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            'execution_lease_expires_at' => $leaseExpiry,
            'execution_heartbeat_at' => $heartbeat,
            'queued_at' => $createdAt, 'started_at' => $createdAt,
            'uploading_at' => $status === 'uploading' ? $createdAt : null,
            'created_at' => $createdAt, 'updated_at' => $heartbeat,
            'expires_at' => $createdAt->modify('+2 hours'),
        ];
    }
}
