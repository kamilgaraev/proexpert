<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportExportAttemptLifecycleStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_claim_renew_and_terminal_failure_are_exact_token_fenced(): void
    {
        Organization::factory()->create(['id' => 7]);
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertRunAndExport('queued', $at, null);
        $store = new EloquentReportExportAttemptLifecycleStore;
        $token = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';

        self::assertTrue($store->claimOrRenew(
            '01J00000000000000000000001',
            $token,
            $at->modify('+960 seconds'),
            $at,
        ));
        self::assertTrue($store->claimOrRenew(
            '01J00000000000000000000001',
            $token,
            $at->modify('+961 seconds'),
            $at->modify('+1 second'),
        ));
        self::assertFalse($store->claimOrRenew(
            '01J00000000000000000000001',
            '0195e44b-a9e7-7f12-a8af-51f2d284d300',
            $at->modify('+962 seconds'),
            $at->modify('+2 seconds'),
        ));
        self::assertTrue($store->failLeased(
            '01J00000000000000000000001',
            $token,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $at->modify('+2 seconds'),
        ));
        self::assertFalse($store->failLeased(
            '01J00000000000000000000001',
            $token,
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $at->modify('+2 seconds'),
        ));

        self::assertSame(
            'failed',
            ReportExportRecord::query()
                ->findOrFail('01J00000000000000000000001')
                ->status,
        );
        self::assertSame(
            1,
            ReportAuditIntentRecord::query()
                ->where('event_type', 'report.export.running')
                ->count(),
        );
        self::assertSame(
            1,
            ReportAuditIntentRecord::query()
                ->where('event_type', 'report.export.failed')
                ->count(),
        );
    }

    private function insertRunAndExport(
        string $status,
        DateTimeImmutable $now,
        ?DateTimeImmutable $leaseExpiry,
    ): void {
        DB::table('report_runs')->insert($this->runFixture($now));
        DB::table('report_exports')->insert($this->export(
            $status,
            $now,
            $leaseExpiry,
        ));
    }

    /** @return array<string, mixed> */
    private function runFixture(DateTimeImmutable $now): array
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
    private function export(
        string $status,
        DateTimeImmutable $now,
        ?DateTimeImmutable $leaseExpiry,
    ): array {
        $active = in_array($status, ['running', 'uploading'], true);

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
            'snapshot_generated_at' => $now, 'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard', 'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]', 'totals_sensitive' => false,
            'totals_audit' => false, 'provenance_audit' => false,
            'contract_version' => '1', 'formula_version' => '1',
            'source_schema_version' => '1', 'renderer_version' => '1',
            'format' => 'csv', 'selected_columns' => '["name"]',
            'sort_field' => 'name', 'sort_direction' => 'asc', 'locale' => 'ru',
            'render_timezone' => 'UTC',
            'execution_lease_token' => $active
                ? '0195e44b-a9e7-7f12-a8af-51f2d284d3ef'
                : null,
            'execution_lease_expires_at' => $active ? $leaseExpiry : null,
            'execution_heartbeat_at' => $active ? $now : null,
            'queued_at' => $now, 'started_at' => $active ? $now : null,
            'uploading_at' => $status === 'uploading' ? $now : null,
            'created_at' => $now, 'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ];
    }
}
