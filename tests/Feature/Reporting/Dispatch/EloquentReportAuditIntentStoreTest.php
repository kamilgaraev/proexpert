<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportAuditIntentStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName(), 'Task 4b audit tests require isolated PostgreSQL.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Organization::factory()->create(['id' => 1]);
    }

    public function test_schema_has_exact_fourteen_event_check_indexes_and_microsecond_instants(): void
    {
        $definition = DB::table('pg_constraint as c')
            ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
            ->where('t.relname', 'report_audit_intents')
            ->where('c.conname', 'report_audit_intents_event_type_check')
            ->value(DB::raw('pg_get_constraintdef(c.oid)'));
        foreach ([
            'report.run.queued', 'report.run.materializing', 'report.run.ready', 'report.run.failed',
            'report.run.cancelled', 'report.run.expired', 'report.export.queued', 'report.export.running',
            'report.export.uploading', 'report.export.ready', 'report.export.failed',
            'report.export.cancelled', 'report.export.expired', 'report.export.artifact_deleted',
        ] as $event) {
            self::assertStringContainsString($event, (string) $definition);
        }

        $indexes = DB::table('pg_indexes')->where('tablename', 'report_audit_intents')->pluck('indexdef', 'indexname');
        self::assertStringContainsString("WHERE (status = 'pending'", $indexes['report_audit_intents_due_idx']);
        self::assertStringContainsString("WHERE (status = 'leased'", $indexes['report_audit_intents_lease_expiry_idx']);
        self::assertStringContainsString('(organization_id, event_type, occurred_at, id)', $indexes['report_audit_intents_organization_event_idx']);

        $precisions = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'report_audit_intents')
            ->whereNotNull('datetime_precision')
            ->pluck('datetime_precision', 'column_name')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        self::assertSame([
            'occurred_at' => 6,
            'available_at' => 6,
            'dispatch_reserved_until' => 6,
            'lease_expires_at' => 6,
            'delivered_at' => 6,
            'dead_lettered_at' => 6,
            'created_at' => 6,
            'updated_at' => 6,
        ], $precisions);
    }

    public function test_due_reservations_advance_beyond_a_permanently_unclaimed_head_batch(): void
    {
        $store = new EloquentReportAuditIntentStore;
        $context = (new ReportExecutionContextBuilder)->build();
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        $subject = [
            'run_id' => '01J00000000000000000000001',
            'report_code' => 'cost_control',
            'status' => 'cancelled',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64),
        ];
        foreach (range(1, 5) as $sequence) {
            DB::transaction(fn () => $store->add(
                "event:cancelled:{$sequence}",
                'report.run.cancelled',
                $context,
                $subject,
                $now,
            ));
        }

        $first = $store->dueIds(2, $now);
        $second = $store->dueIds(2, $now);
        $third = $store->dueIds(2, $now);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertCount(1, $third);
        self::assertCount(5, array_unique([...$first, ...$second, ...$third]));
        self::assertSame([], $store->dueIds(2, $now));
        self::assertSame($first, $store->dueIds(2, $now->modify('+5 minutes')));
    }

    public function test_transaction_required_replay_lease_fencing_reclaim_and_dead_letter(): void
    {
        $store = new EloquentReportAuditIntentStore;
        $context = (new ReportExecutionContextBuilder)->build();
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        $subject = [
            'run_id' => '01J00000000000000000000001',
            'report_code' => 'cost_control',
            'status' => 'cancelled',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64),
        ];

        try {
            $store->add('event:cancelled', 'report.run.cancelled', $context, $subject, $now);
            self::fail('Transaction requirement was bypassed.');
        } catch (LogicException) {
            self::assertSame(0, ReportAuditIntentRecord::query()->count());
        }
        DB::transaction(fn () => $store->add('event:cancelled', 'report.run.cancelled', $context, $subject, $now));
        DB::transaction(fn () => $store->add('event:cancelled', 'report.run.cancelled', $context, $subject, $now));
        self::assertSame(1, ReportAuditIntentRecord::query()->count());

        $id = $store->dueIds(1, $now)[0];
        $firstLease = $store->claim($id, '00000000-0000-4000-8000-000000000099', $now, $now->modify('+30 seconds'));
        self::assertNotNull($firstLease);
        $loaded = $store->loadLeased($id, $firstLease->leaseToken);
        self::assertSame('2026-07-28T10:00:00.123456+00:00', $loaded->occurredAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-07-28T10:00:00.123456+00:00', $loaded->availableAt->format('Y-m-d\TH:i:s.uP'));
        $store->acknowledge($id, '00000000-0000-4000-8000-000000000098', $now);
        $store->failDelivery($id, '00000000-0000-4000-8000-000000000098', ReportErrorCode::REPORT_DEPENDENCY_FAILED, $now, $now);
        self::assertSame('leased', ReportAuditIntentRecord::query()->findOrFail($id)->status);
        self::assertSame(1, ReportAuditIntentRecord::query()->findOrFail($id)->attempt_count);
        $store->failDelivery($id, $firstLease->leaseToken, ReportErrorCode::REPORT_DEPENDENCY_FAILED, $now, $now);
        for ($attempt = 2; $attempt <= 12; $attempt++) {
            $token = '00000000-0000-4000-8000-'.str_pad((string) $attempt, 12, '0', STR_PAD_LEFT);
            $lease = $store->claim($id, $token, $now, $now->modify('+30 seconds'));
            self::assertNotNull($lease);
            $store->failDelivery($id, $token, ReportErrorCode::REPORT_DEPENDENCY_FAILED, $now, $now);
        }

        $record = ReportAuditIntentRecord::query()->findOrFail($id);
        self::assertSame('dead_letter', $record->status);
        self::assertSame(12, $record->attempt_count);
        self::assertSame($subject, $record->subject);
    }

    public function test_reclaiming_expired_twelfth_lease_dead_letters_without_permanent_due_loop(): void
    {
        $store = new EloquentReportAuditIntentStore;
        $context = (new ReportExecutionContextBuilder)->build();
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $subject = [
            'run_id' => '01J00000000000000000000001',
            'report_code' => 'cost_control',
            'status' => 'cancelled',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64),
        ];
        DB::transaction(fn () => $store->add('event:cancelled:crash', 'report.run.cancelled', $context, $subject, $now));
        $record = ReportAuditIntentRecord::query()->where('event_key', 'event:cancelled:crash')->firstOrFail();
        $record->update(['attempt_count' => 11]);
        $lease = $store->claim(
            (string) $record->id,
            '00000000-0000-4000-8000-000000000012',
            $now,
            $now->modify('+30 seconds'),
        );

        self::assertNotNull($lease);
        self::assertSame(1, $store->reclaimExpired(1, $now->modify('+31 seconds')));
        self::assertSame('dead_letter', $record->fresh()->status);
        self::assertSame(12, $record->fresh()->attempt_count);
        self::assertSame([], $store->dueIds(1, $now->modify('+31 seconds')));
    }
}
