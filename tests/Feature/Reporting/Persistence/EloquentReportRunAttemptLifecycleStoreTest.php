<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportRunAttemptLifecycleStoreTest extends TestCase
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

    public function test_live_exact_token_terminalizes_without_current_authorization(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertRun($at->modify('+1 microsecond'));
        $store = new EloquentReportRunAttemptLifecycleStore;

        self::assertTrue($store->failLeased(
            '01J00000000000000000000001',
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $at,
        ));
        self::assertFalse($store->failLeased(
            '01J00000000000000000000001',
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $at,
        ));
        self::assertSame('failed', ReportRunRecord::query()->findOrFail('01J00000000000000000000001')->status);
        self::assertSame(1, ReportAuditIntentRecord::query()->where('event_type', 'report.run.failed')->count());
    }

    public function test_expired_or_mismatched_token_is_stale_and_audit_failure_rolls_back(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertRun($at->modify('+1 second'));
        $store = new EloquentReportRunAttemptLifecycleStore;
        self::assertFalse($store->failLeased(
            '01J00000000000000000000001',
            '0195e44b-a9e7-7f12-a8af-51f2d284d300',
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            $at,
        ));

        ReportAuditIntentRecord::query()->create([
            'id' => '01J00000000000000000000009',
            'event_key' => 'reports:run:01J00000000000000000000001:failed:REPORT_INTERNAL_ERROR',
            'event_type' => 'report.run.failed',
            'organization_id' => 7,
            'actor_id' => 17,
            'subject' => ['conflict' => true],
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => $at,
            'available_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        try {
            $store->failLeased(
                '01J00000000000000000000001',
                '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
                ReportErrorCode::REPORT_INTERNAL_ERROR,
                $at,
            );
            self::fail('Expected audit rollback.');
        } catch (\LogicException $exception) {
            self::assertSame('report_audit_event_key_conflict', $exception->getMessage());
        }
        self::assertSame('materializing', ReportRunRecord::query()->findOrFail('01J00000000000000000000001')->status);
    }

    public function test_authority_free_claim_and_same_token_renewal_are_aba_fenced(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $this->insertQueuedRun($at);
        $store = new EloquentReportRunAttemptLifecycleStore;
        $token = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';

        self::assertTrue($store->claimOrRenew('01J00000000000000000000002', $token, $at->modify('+960 seconds'), $at));
        self::assertTrue($store->claimOrRenew('01J00000000000000000000002', $token, $at->modify('+961 seconds'), $at->modify('+1 second')));
        self::assertFalse($store->claimOrRenew(
            '01J00000000000000000000002',
            '0195e44b-a9e7-7f12-a8af-51f2d284d300',
            $at->modify('+962 seconds'),
            $at->modify('+2 seconds'),
        ));
        $run = ReportRunRecord::query()->findOrFail('01J00000000000000000000002');
        self::assertSame($token, $run->execution_lease_token);
        self::assertSame(1, ReportAuditIntentRecord::query()->where('event_type', 'report.run.materializing')->count());
    }

    private function insertRun(DateTimeImmutable $leaseExpiresAt): void
    {
        $now = $leaseExpiresAt->modify('-10 minutes');
        ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000001', 'organization_id' => 7, 'requester_actor_id' => 17,
            'report_code' => 'cost_control', 'status' => 'materializing',
            'definition_hash' => str_repeat('a', 64), 'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64), 'idempotency_key_hash' => str_repeat('d', 64),
            'input_fingerprint' => str_repeat('e', 64), 'contract_version' => '1', 'formula_version' => '1',
            'source_schema_version' => '1', 'renderer_version' => '1', 'definition_snapshot' => [],
            'canonical_query_json' => '{}', 'scope_holding_organization_ids' => [7], 'scope_project_ids' => [],
            'scope_resources' => [], 'scope_timezone' => 'UTC', 'filters' => [], 'comparison' => [],
            'as_of' => $now, 'locale' => 'ru', 'snapshot_classification' => 'operational',
            'data_classification' => 'standard', 'sensitive_column_ids' => [], 'audit_column_ids' => [],
            'progress' => 10, 'totals' => [], 'execution_lease_token' => '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            'execution_lease_expires_at' => $leaseExpiresAt, 'execution_heartbeat_at' => $now,
            'queued_at' => $now, 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }

    private function insertQueuedRun(DateTimeImmutable $now): void
    {
        ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000002', 'organization_id' => 7, 'requester_actor_id' => 17,
            'report_code' => 'cost_control', 'status' => 'queued',
            'definition_hash' => str_repeat('a', 64), 'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64), 'idempotency_key_hash' => str_repeat('f', 64),
            'input_fingerprint' => str_repeat('e', 64), 'contract_version' => '1', 'formula_version' => '1',
            'source_schema_version' => '1', 'renderer_version' => '1', 'definition_snapshot' => [],
            'canonical_query_json' => '{}', 'scope_holding_organization_ids' => [7], 'scope_project_ids' => [],
            'scope_resources' => [], 'scope_timezone' => 'UTC', 'filters' => [], 'comparison' => [],
            'as_of' => $now, 'locale' => 'ru', 'snapshot_classification' => 'operational',
            'data_classification' => 'standard', 'sensitive_column_ids' => [], 'audit_column_ids' => [],
            'progress' => 0, 'totals' => [], 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }
}
