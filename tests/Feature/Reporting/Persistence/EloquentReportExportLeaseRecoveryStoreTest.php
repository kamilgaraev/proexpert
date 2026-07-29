<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
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
