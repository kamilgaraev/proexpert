<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Retention;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Retention\DeleteExpiredReportArtifactsService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\Models\Organization;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DeleteExpiredReportArtifactsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_bounded_batches_progress_past_successfully_deleted_rows(): void
    {
        $organization = Organization::factory()->create();
        $this->insertRun((int) $organization->id);
        foreach ([1, 2, 3] as $sequence) {
            $this->insertExpiredExport((int) $organization->id, $sequence);
        }

        $files = $this->mock(FileService::class);
        $files->shouldReceive('deleteVersion')->times(3);
        $service = new DeleteExpiredReportArtifactsService(
            $files,
            new RetentionAuditFake,
            new ReportExportHydrator,
            3600,
        );
        $now = new DateTimeImmutable('2026-07-30T10:00:00.123456Z');

        self::assertSame(2, $service->delete(2, $now)['deleted']);
        self::assertSame(1, $service->delete(2, $now)['deleted']);
        self::assertSame(3, DB::table('report_exports')->whereNotNull('artifact_deleted_at')->count());
    }

    public function test_permanently_failing_head_row_is_deferred_and_does_not_starve_later_batches(): void
    {
        $organization = Organization::factory()->create();
        $this->insertRun((int) $organization->id);
        foreach ([1, 2, 3] as $sequence) {
            $this->insertExpiredExport((int) $organization->id, $sequence);
        }
        DB::table('report_exports')
            ->where('id', '01J00000000000000000000001')
            ->update(['artifact_path' => 'org-999/reports/wrong-tenant.csv']);

        $files = $this->mock(FileService::class);
        $files->shouldReceive('deleteVersion')->twice();
        $service = new DeleteExpiredReportArtifactsService(
            $files,
            new RetentionAuditFake,
            new ReportExportHydrator,
            3600,
        );
        $now = new DateTimeImmutable('2026-07-30T10:00:00.123456Z');

        $first = $service->delete(2, $now);
        $second = $service->delete(2, $now);

        self::assertSame(1, $first['failed']);
        self::assertSame(1, $first['deleted']);
        self::assertSame(1, $second['deleted']);
        self::assertSame(2, DB::table('report_exports')->whereNotNull('artifact_deleted_at')->count());
        self::assertNotNull(
            DB::table('report_exports')
                ->where('id', '01J00000000000000000000001')
                ->value('artifact_deletion_next_attempt_at'),
        );
    }

    public function test_audit_failure_after_exact_version_delete_releases_fence_and_retries_safely(): void
    {
        $organization = Organization::factory()->create();
        $this->insertRun((int) $organization->id);
        $this->insertExpiredExport((int) $organization->id, 1);
        $path = 'org-'.$organization->id.'/reports/export-1.csv';

        $files = $this->mock(FileService::class);
        $files->shouldReceive('deleteVersion')
            ->once()
            ->with($path, 'version-1');
        $audit = new RetentionAuditFake(1);
        $service = new DeleteExpiredReportArtifactsService(
            $files,
            $audit,
            new ReportExportHydrator,
            3600,
        );
        $now = new DateTimeImmutable('2026-07-30T10:00:00.123456Z');

        self::assertSame(1, $service->delete(1, $now)['failed']);
        self::assertNull(DB::table('report_exports')->value('artifact_deleted_at'));
        self::assertNull(DB::table('report_exports')->value('artifact_deletion_lease_token'));
        self::assertNotNull(DB::table('report_exports')->value('artifact_deletion_storage_accepted_at'));

        self::assertSame(1, $service->delete(1, $now->modify('+3 seconds'))['deleted']);
        self::assertNotNull(DB::table('report_exports')->value('artifact_deleted_at'));
        self::assertCount(1, $audit->events);
    }

    public function test_expired_pre_delete_fence_recovers_crash_after_storage_delete_before_finalize(): void
    {
        $organization = Organization::factory()->create();
        $this->insertRun((int) $organization->id);
        $this->insertExpiredExport((int) $organization->id, 1);
        DB::table('report_exports')->update([
            'artifact_deletion_lease_token' => '00000000-0000-4000-8000-000000000001',
            'artifact_deletion_lease_expires_at' => new DateTimeImmutable('2026-07-30T09:00:00Z'),
            'artifact_deletion_attempt_count' => 1,
        ]);

        $files = $this->mock(FileService::class);
        $files->shouldReceive('deleteVersion')
            ->once()
            ->with('org-'.$organization->id.'/reports/export-1.csv', 'version-1');
        $service = new DeleteExpiredReportArtifactsService(
            $files,
            new RetentionAuditFake,
            new ReportExportHydrator,
            3600,
        );

        self::assertSame(
            1,
            $service->delete(1, new DateTimeImmutable('2026-07-30T10:00:00.123456Z'))['deleted'],
        );
        self::assertNotNull(DB::table('report_exports')->value('artifact_deleted_at'));
        self::assertSame(2, DB::table('report_exports')->value('artifact_deletion_attempt_count'));
    }

    private function insertRun(int $organizationId): void
    {
        $createdAt = new DateTimeImmutable('2026-07-01T00:00:00Z');
        DB::table('report_runs')->insert([
            'id' => '01J00000000000000000000000',
            'organization_id' => $organizationId,
            'requester_actor_id' => 17,
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
            'definition_snapshot' => '{}',
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => "[$organizationId]",
            'scope_project_ids' => '[]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'filters' => '[]',
            'comparison' => '[]',
            'as_of' => $createdAt,
            'locale' => 'ru',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'progress' => 0,
            'totals' => '[]',
            'queued_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'expires_at' => $createdAt->modify('+2 hours'),
        ]);
    }

    private function insertExpiredExport(int $organizationId, int $sequence): void
    {
        $createdAt = new DateTimeImmutable("2026-07-01T0{$sequence}:00:00Z");
        $expiredAt = new DateTimeImmutable("2026-07-02T0{$sequence}:00:00Z");
        DB::table('report_exports')->insert([
            'id' => '01J0000000000000000000000'.$sequence,
            'run_id' => '01J00000000000000000000000',
            'organization_id' => $organizationId,
            'requester_actor_id' => 17,
            'report_code' => 'cost_control',
            'status' => 'expired',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('c', 64),
            'source_hash' => str_repeat('1', 64),
            'result_hash' => str_repeat('2', 64),
            'export_hash' => str_repeat((string) $sequence, 64),
            'idempotency_key_hash' => str_repeat(dechex($sequence + 3), 64),
            'input_fingerprint' => str_repeat(dechex($sequence + 6), 64),
            'scope_holding_organization_ids' => "[$organizationId]",
            'scope_project_ids' => '[]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'report',
            'snapshot_id' => 'snapshot-'.$sequence,
            'snapshot_generated_at' => $createdAt,
            'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'totals_sensitive' => false,
            'totals_audit' => false,
            'provenance_audit' => false,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'format' => 'csv',
            'selected_columns' => '["name"]',
            'sort_field' => 'name',
            'sort_direction' => 'asc',
            'locale' => 'ru',
            'render_timezone' => 'UTC',
            'artifact_path' => "org-{$organizationId}/reports/export-{$sequence}.csv",
            'artifact_version_id' => 'version-'.$sequence,
            'artifact_etag' => 'etag-'.$sequence,
            'artifact_mime' => 'text/csv',
            'artifact_checksum' => str_repeat('f', 64),
            'artifact_size_bytes' => 100,
            'row_count' => 1,
            'queued_at' => $createdAt,
            'ready_at' => $createdAt->modify('+1 minute'),
            'expired_at' => $expiredAt,
            'created_at' => $createdAt,
            'updated_at' => $expiredAt,
            'expires_at' => $createdAt->modify('+2 hours'),
        ]);
    }
}

final class RetentionAuditFake implements ReportTransitionAudit
{
    public array $events = [];

    public function __construct(private int $failuresRemaining = 0) {}

    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('audit unavailable');
        }

        $this->events[] = compact('eventId', 'eventType', 'context', 'subject', 'occurredAt');
    }
}
