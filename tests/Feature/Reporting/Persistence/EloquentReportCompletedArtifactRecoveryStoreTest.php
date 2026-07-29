<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\Models\Organization;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportCompletedArtifactRecoveryStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_only_expired_uploading_lease_can_be_reclaimed(): void
    {
        Organization::factory()->create(['id' => 7]);
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $fixture = new CompletedArtifactRecoveryFixture;
        DB::table('report_runs')->insert($fixture->run($at->modify('-20 minutes')));
        DB::table('report_exports')->insert($fixture->export(
            'uploading',
            $at->modify('-20 minutes'),
            $at->modify('-1 microsecond'),
            $at->modify('-961 seconds'),
        ));
        $scope = new ReportScope(7, [7], [], [], new DateTimeZone('UTC'));
        $base = (new ReportExecutionContextBuilder)->build();
        $context = (new ReportExecutionContextBuilder)
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
        $newToken = '0195e44b-a9e7-7f12-a8af-51f2d284d300';
        $store = new EloquentReportCompletedArtifactRecoveryStore(
            new ReportExportHydrator,
        );
        $intents = new CompletedArtifactDispatchIntentStore;
        $watchdog = new EloquentReportExportLeaseRecoveryStore($intents);
        $candidate = $watchdog->due(1, $at)[0];

        $claimed = $store->claimExpiredUpload(
            $context,
            '01J00000000000000000000001',
            $newToken,
            $at->modify('+960 seconds'),
            $at,
        );

        self::assertSame('uploading', $claimed->status->value);
        self::assertSame(
            $newToken,
            ReportExportRecord::query()
                ->findOrFail('01J00000000000000000000001')
                ->execution_lease_token,
        );
        self::assertFalse($watchdog->requeue($candidate, $at));
        self::assertSame([], $intents->exports);
        $this->expectException(
            \App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class,
        );
        $store->claimExpiredUpload(
            $context,
            '01J00000000000000000000001',
            '0195e44b-a9e7-7f12-a8af-51f2d284d301',
            $at->modify('+960 seconds'),
            $at,
        );
    }

    public function test_watchdog_winner_fences_completed_artifact_reconciler(): void
    {
        Organization::factory()->create(['id' => 7]);
        $at = new DateTimeImmutable('2026-07-26T10:00:00.123456Z');
        $fixture = new CompletedArtifactRecoveryFixture;
        DB::table('report_runs')->insert($fixture->run($at->modify('-20 minutes')));
        DB::table('report_exports')->insert($fixture->export(
            'uploading',
            $at->modify('-20 minutes'),
            $at->modify('-1 microsecond'),
            $at->modify('-961 seconds'),
        ));
        $intents = new CompletedArtifactDispatchIntentStore;
        $watchdog = new EloquentReportExportLeaseRecoveryStore($intents);
        $candidate = $watchdog->due(1, $at)[0];
        self::assertTrue($watchdog->requeue($candidate, $at));

        $scope = new ReportScope(7, [7], [], [], new DateTimeZone('UTC'));
        $base = (new ReportExecutionContextBuilder)->build();
        $context = (new ReportExecutionContextBuilder)
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
        $store = new EloquentReportCompletedArtifactRecoveryStore(
            new ReportExportHydrator,
        );

        try {
            $store->claimExpiredUpload(
                $context,
                '01J00000000000000000000001',
                '0195e44b-a9e7-7f12-a8af-51f2d284d300',
                $at->modify('+960 seconds'),
                $at,
            );
            self::fail('Reconciler claimed a watchdog-requeued export.');
        } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_EXPORT_NOT_READY, $exception->errorCode);
        }

        $record = ReportExportRecord::query()
            ->findOrFail('01J00000000000000000000001');
        self::assertSame('queued', $record->status);
        self::assertNull($record->execution_lease_token);
        self::assertCount(1, $intents->exports);
    }
}

final class CompletedArtifactDispatchIntentStore implements ReportDispatchIntentStore
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
        $this->exports[] = func_get_args();
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

final class CompletedArtifactRecoveryFixture
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
            'uploading_at' => $createdAt, 'created_at' => $createdAt,
            'updated_at' => $heartbeat,
            'expires_at' => $createdAt->modify('+2 hours'),
        ];
    }
}
