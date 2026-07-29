<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportDispatchIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportDispatchIntentStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName(), 'Task 4b dispatch tests require isolated PostgreSQL.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Organization::factory()->create(['id' => 1]);
    }

    public function test_schema_has_exact_constraints_indexes_and_microsecond_instants(): void
    {
        $constraints = DB::table('pg_constraint as c')
            ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
            ->where('t.relname', 'report_dispatch_intents')
            ->pluck('c.conname')
            ->sort()
            ->values()
            ->all();

        foreach ([
            'report_dispatch_intents_aggregate_check',
            'report_dispatch_intents_attempt_check',
            'report_dispatch_intents_event_key_unique',
            'report_dispatch_intents_lease_shape_check',
            'report_dispatch_intents_organization_fk',
            'report_dispatch_intents_status_check',
            'report_dispatch_intents_terminal_shape_check',
            'report_dispatch_intents_topic_check',
        ] as $constraint) {
            self::assertContains($constraint, $constraints);
        }

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'report_dispatch_intents')
            ->pluck('indexdef', 'indexname');
        self::assertStringContainsString("WHERE (status = 'pending'", $indexes['report_dispatch_intents_due_idx']);
        self::assertStringContainsString("WHERE (status = 'leased'", $indexes['report_dispatch_intents_lease_expiry_idx']);
        self::assertStringContainsString('(organization_id, aggregate_type, aggregate_id)', $indexes['report_dispatch_intents_aggregate_idx']);

        $precisions = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'report_dispatch_intents')
            ->whereNotNull('datetime_precision')
            ->pluck('datetime_precision', 'column_name')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        self::assertSame([
            'occurred_at' => 6,
            'available_at' => 6,
            'lease_expires_at' => 6,
            'published_at' => 6,
            'dead_lettered_at' => 6,
            'created_at' => 6,
            'updated_at' => 6,
        ], $precisions);
    }

    public function test_add_requires_transaction_and_claim_is_skip_locked_fenced(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');

        try {
            $store->addRunIntent('01J00000000000000000000001', 1, 'reports:run:01J00000000000000000000001:materialize:initial', $now);
            self::fail('Transaction requirement was bypassed.');
        } catch (LogicException) {
            self::assertSame(0, ReportDispatchIntentRecord::query()->count());
        }

        DB::transaction(fn () => $store->addRunIntent(
            '01J00000000000000000000001',
            1,
            'reports:run:01J00000000000000000000001:materialize:initial',
            $now,
        ));
        $leases = $store->claimDue(
            1,
            $now,
            new DateTimeImmutable('2026-07-28T10:00:30.123456Z'),
            '00000000-0000-4000-8000-000000000001',
        );

        self::assertCount(1, $leases);
        self::assertSame(1, $leases[0]->intent->attemptCount);
        self::assertSame('2026-07-28T10:00:00.123456+00:00', $leases[0]->intent->occurredAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-07-28T10:00:00.123456+00:00', $leases[0]->intent->availableAt->format('Y-m-d\TH:i:s.uP'));
        $store->markPublished($leases[0]->intent->id, '00000000-0000-4000-8000-000000000099', $now);
        self::assertSame('leased', ReportDispatchIntentRecord::query()->findOrFail($leases[0]->intent->id)->status);
        $store->markPublished($leases[0]->intent->id, $leases[0]->leaseToken, $now);
        self::assertSame('published', ReportDispatchIntentRecord::query()->findOrFail($leases[0]->intent->id)->status);
    }

    public function test_reclaim_does_not_increment_attempt_and_attempt_twelve_dead_letters_and_fails_only_queued_run(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $run = ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000001',
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'cost_control',
            'status' => 'queued',
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
            'scope_holding_organization_ids' => [1],
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
            'progress' => 0,
            'totals' => [],
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
        DB::transaction(fn () => $store->addRunIntent(
            (string) $run->id,
            (int) $run->organization_id,
            "reports:run:{$run->id}:materialize:initial",
            $now,
        ));

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $lease = $store->claimDue(1, $now, $now->modify('+30 seconds'), '00000000-0000-4000-8000-'.str_pad((string) $attempt, 12, '0', STR_PAD_LEFT))[0];
            $store->markPublicationFailed($lease->intent->id, $lease->leaseToken, ReportErrorCode::REPORT_DEPENDENCY_FAILED, $now, $now);
        }
        $lease = $store->claimDue(1, $now, $now->modify('+30 seconds'), '00000000-0000-4000-8000-000000000012')[0];
        $store->markPublicationFailed($lease->intent->id, $lease->leaseToken, ReportErrorCode::REPORT_DEPENDENCY_FAILED, $now, $now);

        self::assertSame('dead_letter', ReportDispatchIntentRecord::query()->findOrFail($lease->intent->id)->status);
        self::assertSame('failed', $run->fresh()->status);
        self::assertSame('REPORT_DEPENDENCY_FAILED', $run->fresh()->error_code);
        self::assertSame(1, ReportAuditIntentRecord::query()
            ->where('event_type', 'report.run.failed')
            ->where('event_key', "reports:run:{$run->id}:failed:REPORT_DEPENDENCY_FAILED")
            ->count());
    }

    public function test_stale_publication_failure_token_cannot_mutate_current_lease_but_current_token_succeeds(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        DB::transaction(fn () => $store->addRunIntent(
            '01J00000000000000000000001',
            1,
            'reports:run:01J00000000000000000000001:materialize:stale-failure',
            $now,
        ));
        $lease = $store->claimDue(
            1,
            $now,
            $now->modify('+30 seconds'),
            '00000000-0000-4000-8000-000000000001',
        )[0];
        $failureAt = $now->modify('+1 second');
        $nextAttemptAt = $failureAt->modify('+15 seconds');
        $before = ReportDispatchIntentRecord::query()->findOrFail($lease->intent->id);
        $expected = [
            'status' => $before->status,
            'attempt_count' => $before->attempt_count,
            'lease_token' => $before->lease_token,
            'lease_expires_at' => $before->getRawOriginal('lease_expires_at'),
            'available_at' => $before->getRawOriginal('available_at'),
            'last_error_code' => $before->last_error_code,
            'updated_at' => $before->getRawOriginal('updated_at'),
        ];

        $store->markPublicationFailed(
            $lease->intent->id,
            '00000000-0000-4000-8000-000000000099',
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $failureAt,
            $nextAttemptAt,
        );

        $afterStale = ReportDispatchIntentRecord::query()->findOrFail($lease->intent->id);
        self::assertSame($expected, [
            'status' => $afterStale->status,
            'attempt_count' => $afterStale->attempt_count,
            'lease_token' => $afterStale->lease_token,
            'lease_expires_at' => $afterStale->getRawOriginal('lease_expires_at'),
            'available_at' => $afterStale->getRawOriginal('available_at'),
            'last_error_code' => $afterStale->last_error_code,
            'updated_at' => $afterStale->getRawOriginal('updated_at'),
        ]);

        $store->markPublicationFailed(
            $lease->intent->id,
            $lease->leaseToken,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $failureAt,
            $nextAttemptAt,
        );
        $afterCurrent = ReportDispatchIntentRecord::query()->findOrFail($lease->intent->id);
        self::assertSame('pending', $afterCurrent->status);
        self::assertSame(1, $afterCurrent->attempt_count);
        self::assertNull($afterCurrent->lease_token);
        self::assertNull($afterCurrent->lease_expires_at);
        self::assertSame('REPORT_DEPENDENCY_FAILED', $afterCurrent->last_error_code);
        self::assertSame(
            '2026-07-28 10:00:16.123456+00',
            $afterCurrent->getRawOriginal('available_at'),
        );
        self::assertSame(
            '2026-07-28 10:00:01.123456+00',
            $afterCurrent->getRawOriginal('updated_at'),
        );
    }

    public function test_reclaiming_expired_twelfth_lease_dead_letters_and_audits_queued_run(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $run = ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000002',
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'cost_control',
            'status' => 'queued',
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
            'scope_holding_organization_ids' => [1],
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
            'progress' => 0,
            'totals' => [],
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
        DB::transaction(fn () => $store->addRunIntent(
            (string) $run->id,
            1,
            "reports:run:{$run->id}:materialize:initial",
            $now,
        ));
        $intent = ReportDispatchIntentRecord::query()->where('aggregate_id', $run->id)->firstOrFail();
        $intent->update(['attempt_count' => 11]);
        $lease = $store->claimDue(1, $now, $now->modify('+30 seconds'), '00000000-0000-4000-8000-000000000012')[0];

        self::assertSame(1, $store->reclaimExpiredLeases(1, $now->modify('+31 seconds')));
        self::assertSame('dead_letter', $intent->fresh()->status);
        self::assertSame(12, $intent->fresh()->attempt_count);
        self::assertSame('failed', $run->fresh()->status);
        self::assertSame([], $store->claimDue(1, $now->modify('+31 seconds'), $now->modify('+1 minute'), '00000000-0000-4000-8000-000000000013'));
        self::assertSame(1, ReportAuditIntentRecord::query()
            ->where('event_type', 'report.run.failed')
            ->count());
        self::assertSame(12, $lease->intent->attemptCount);
    }

    public function test_competing_postgresql_claim_skips_locked_row_and_returns_disjoint_intent(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        DB::transaction(function () use ($store, $now): void {
            $store->addRunIntent('01J00000000000000000000001', 1, 'event:one', $now);
            $store->addRunIntent('01J00000000000000000000002', 1, 'event:two', $now);
        });
        $primary = DB::connection();
        config(['database.connections.reporting_competitor' => config('database.connections.pgsql')]);
        $competitor = DB::connection('reporting_competitor');
        $primary->beginTransaction();
        try {
            $lockedId = $primary->table('report_dispatch_intents')
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->value('id');
            DB::setDefaultConnection('reporting_competitor');
            $leases = $store->claimDue(
                1,
                $now,
                $now->modify('+30 seconds'),
                '00000000-0000-4000-8000-000000000001',
            );

            self::assertCount(1, $leases);
            self::assertNotSame($lockedId, $leases[0]->intent->id);
        } finally {
            DB::setDefaultConnection('pgsql');
            $primary->rollBack();
            $competitor->disconnect();
            DB::purge('reporting_competitor');
        }
    }

    public function test_attempt_twelve_export_failure_branch_is_owned_by_the_closed_dispatch_store(): void
    {
        $reflection = new \ReflectionClass(EloquentReportDispatchIntentStore::class);

        self::assertTrue($reflection->hasMethod('markPublicationFailed'));
        self::assertTrue($reflection->hasMethod('failQueuedExport'));
        self::assertFalse($reflection->getMethod('failQueuedExport')->isPublic());
    }

    public function test_attempt_twelve_export_publication_failure_dead_letters_queued_export_and_audits_atomically(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);

        $store->markPublicationFailed(
            $lease->intent->id,
            $lease->leaseToken,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $now,
            $now,
        );

        self::assertSame('dead_letter', $lease->intent->fresh()->status);
        self::assertSame('failed', $export->fresh()->status);
        self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED->value, $export->fresh()->error_code);
        self::assertSame(1, ReportAuditIntentRecord::query()
            ->where('event_type', 'report.export.failed')
            ->where('event_key', "reports:export:{$export->id}:failed:REPORT_DEPENDENCY_FAILED")
            ->count());
    }

    public function test_stale_export_failure_token_preserves_complete_intent_and_export_state(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);
        $intentBefore = $this->intentSnapshot($lease->intent);
        $exportBefore = $this->exportSnapshot($export);

        $store->markPublicationFailed(
            $lease->intent->id,
            '00000000-0000-4000-8000-000000000099',
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $now,
            $now,
        );

        self::assertSame($intentBefore, $this->intentSnapshot($lease->intent));
        self::assertSame($exportBefore, $this->exportSnapshot($export));
        self::assertSame(0, ReportAuditIntentRecord::query()->count());
    }

    public function test_reclaiming_expired_twelfth_export_lease_dead_letters_queued_export_and_audits_atomically(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);

        self::assertSame(1, $store->reclaimExpiredLeases(1, $now->modify('+31 seconds')));
        self::assertSame('dead_letter', $lease->intent->fresh()->status);
        self::assertSame('failed', $export->fresh()->status);
        self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED->value, $export->fresh()->error_code);
        self::assertSame(1, ReportAuditIntentRecord::query()
            ->where('event_type', 'report.export.failed')
            ->where('event_key', "reports:export:{$export->id}:failed:REPORT_DEPENDENCY_FAILED")
            ->count());
    }

    public function test_export_terminal_transition_rolls_back_when_outbox_audit_append_fails(): void
    {
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $store = $this->store();
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);
        $this->createConflictingExportFailureAudit($export, $now);
        $intentBefore = $this->intentSnapshot($lease->intent);
        $exportBefore = $this->exportSnapshot($export);

        try {
            $store->markPublicationFailed(
                $lease->intent->id,
                $lease->leaseToken,
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                $now,
                $now,
            );
            self::fail('Expected outbox audit conflict to abort the transaction.');
        } catch (LogicException) {
        }

        self::assertSame($intentBefore, $this->intentSnapshot($lease->intent));
        self::assertSame($exportBefore, $this->exportSnapshot($export));
        self::assertSame(1, ReportAuditIntentRecord::query()->count());
        self::assertSame(0, ReportAuditIntentRecord::query()->where('event_type', 'report.export.failed')->count());
    }

    public function test_reclaiming_export_terminal_transition_rolls_back_when_outbox_audit_append_fails(): void
    {
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $store = $this->store();
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);
        $this->createConflictingExportFailureAudit($export, $now);
        $intentBefore = $this->intentSnapshot($lease->intent);
        $exportBefore = $this->exportSnapshot($export);

        try {
            $store->reclaimExpiredLeases(1, $now->modify('+31 seconds'));
            self::fail('Expected outbox audit conflict to abort the transaction.');
        } catch (LogicException) {
        }

        self::assertSame($intentBefore, $this->intentSnapshot($lease->intent));
        self::assertSame($exportBefore, $this->exportSnapshot($export));
        self::assertSame(1, ReportAuditIntentRecord::query()->count());
        self::assertSame(0, ReportAuditIntentRecord::query()->where('event_type', 'report.export.failed')->count());
    }

    public function test_non_queued_export_is_not_mutated_by_terminal_dispatch_failure(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $export->update([
            'status' => 'failed',
            'error_code' => ReportErrorCode::REPORT_DEPENDENCY_FAILED->value,
            'failed_at' => $now,
        ]);
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);
        $intentBefore = $this->intentSnapshot($lease->intent);
        $exportBefore = $this->exportSnapshot($export);

        $store->markPublicationFailed(
            $lease->intent->id,
            $lease->leaseToken,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $now,
            $now,
        );

        self::assertSame($intentBefore, $this->intentSnapshot($lease->intent));
        self::assertSame($exportBefore, $this->exportSnapshot($export));
        self::assertSame(0, ReportAuditIntentRecord::query()->count());
    }

    public function test_non_queued_export_is_not_mutated_by_terminal_dispatch_reclaim(): void
    {
        $store = $this->store();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $export = $this->createQueuedExport($now);
        $export->update([
            'status' => 'failed',
            'error_code' => ReportErrorCode::REPORT_DEPENDENCY_FAILED->value,
            'failed_at' => $now,
        ]);
        $lease = $this->leaseTwelfthExportIntent($store, $export, $now);
        $intentBefore = $this->intentSnapshot($lease->intent);
        $exportBefore = $this->exportSnapshot($export);

        self::assertSame(0, $store->reclaimExpiredLeases(1, $now->modify('+31 seconds')));
        self::assertSame($intentBefore, $this->intentSnapshot($lease->intent));
        self::assertSame($exportBefore, $this->exportSnapshot($export));
        self::assertSame(0, ReportAuditIntentRecord::query()->count());
    }

    private function store(): EloquentReportDispatchIntentStore
    {
        return new EloquentReportDispatchIntentStore(
            new OutboxReportTransitionAudit(new EloquentReportAuditIntentStore),
        );
    }

    private function createQueuedExport(DateTimeImmutable $now): ReportExportRecord
    {
        $runId = '01J00000000000000000000003';
        $exportId = '01J00000000000000000000004';
        ReportRunRecord::query()->create([
            'id' => $runId,
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'cost_control',
            'status' => 'queued',
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
            'scope_holding_organization_ids' => [1],
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
            'progress' => 0,
            'totals' => [],
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);

        return ReportExportRecord::query()->create([
            'id' => $exportId,
            'run_id' => $runId,
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'cost_control',
            'status' => 'queued',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('c', 64),
            'source_hash' => str_repeat('d', 64),
            'result_hash' => str_repeat('e', 64),
            'export_hash' => str_repeat('f', 64),
            'idempotency_key_hash' => str_repeat('1', 64),
            'input_fingerprint' => str_repeat('2', 64),
            'scope_holding_organization_ids' => [1],
            'scope_project_ids' => [],
            'scope_resources' => [],
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'report_run',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => $now,
            'snapshot_watermarks' => [],
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => [],
            'audit_column_ids' => [],
            'totals_sensitive' => false,
            'totals_audit' => false,
            'provenance_audit' => false,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'format' => 'csv',
            'selected_columns' => [],
            'sort_field' => 'name',
            'sort_direction' => 'asc',
            'locale' => 'ru',
            'render_timezone' => 'UTC',
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }

    private function leaseTwelfthExportIntent(EloquentReportDispatchIntentStore $store, ReportExportRecord $export, DateTimeImmutable $now): \App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchLease
    {
        DB::transaction(fn () => $store->addExportIntent(
            (string) $export->id,
            (int) $export->organization_id,
            "reports:export:{$export->id}:generate:initial",
            $now,
        ));
        $intent = ReportDispatchIntentRecord::query()->where('aggregate_id', $export->id)->firstOrFail();
        $intent->update(['attempt_count' => 11]);

        return $store->claimDue(1, $now, $now->modify('+30 seconds'), '00000000-0000-4000-8000-000000000012')[0];
    }

    private function createConflictingExportFailureAudit(ReportExportRecord $export, DateTimeImmutable $now): void
    {
        ReportAuditIntentRecord::query()->create([
            'id' => '01J00000000000000000000005',
            'event_key' => "reports:export:{$export->id}:failed:REPORT_DEPENDENCY_FAILED",
            'event_type' => 'report.export.cancelled',
            'organization_id' => 1,
            'actor_id' => 1,
            'subject' => ['kind' => 'conflict'],
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => $now,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function intentSnapshot(ReportDispatchIntentRecord $intent): array
    {
        return ReportDispatchIntentRecord::query()->findOrFail($intent->id)->getAttributes();
    }

    private function exportSnapshot(ReportExportRecord $export): array
    {
        return ReportExportRecord::query()->findOrFail($export->id)->getAttributes();
    }
}
