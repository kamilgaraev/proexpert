<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportExportHydratorTest extends TestCase
{
    #[DataProvider('activeStatuses')]
    public function test_hydrates_complete_active_export_with_configured_poll(ReportExportStatus $status): void
    {
        $lease = $status === ReportExportStatus::QUEUED ? [] : [
            'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
            'execution_lease_expires_at' => new DateTimeImmutable('2026-07-29T10:16:00.123456Z'),
            'execution_heartbeat_at' => new DateTimeImmutable('2026-07-29T10:00:00.123456Z'),
        ];
        $record = $this->record(['status' => $status->value, ...$lease]);

        $export = (new ReportExportHydrator)->hydrate($record, 'reused', 1250);

        self::assertSame($status, $export->status);
        self::assertSame('01J00000000000000000000001', $export->id);
        self::assertSame('01J00000000000000000000002', $export->runId);
        self::assertSame(['amount', 'name'], $export->columns);
        self::assertSame('amount', $export->sort->field);
        self::assertSame(1250, $export->pollAfterMs);
        self::assertNull($export->artifactPath);
    }

    public static function activeStatuses(): iterable
    {
        yield [ReportExportStatus::QUEUED];
        yield [ReportExportStatus::RUNNING];
        yield [ReportExportStatus::UPLOADING];
    }

    public function test_ready_preserves_exact_artifact_version_identity(): void
    {
        $readyAt = new DateTimeImmutable('2026-07-29T10:02:00.123456Z');
        $record = $this->record([
            'status' => 'ready',
            'artifact_path' => 'org-1/reports/export.csv',
            'artifact_version_id' => '3LgY4fExactVersion',
            'artifact_etag' => '"7f83b1657ff1fc53b92dc18148a1d65dfa13514a2096"-3',
            'artifact_mime' => 'text/csv',
            'artifact_checksum' => str_repeat('f', 64),
            'artifact_size_bytes' => 412,
            'row_count' => 7,
            'ready_at' => $readyAt,
            'updated_at' => $readyAt,
        ]);

        $export = (new ReportExportHydrator)->hydrate($record, 'created', 1250);

        self::assertSame('3LgY4fExactVersion', $export->versionId);
        self::assertSame('"7f83b1657ff1fc53b92dc18148a1d65dfa13514a2096"-3', $export->etag);
        self::assertSame(str_repeat('f', 64), $export->checksum?->value);
        self::assertSame(412, $export->sizeBytes);
        self::assertSame(7, $export->rowCount);
        self::assertNull($export->pollAfterMs);
    }

    public function test_expired_validates_retained_artifact_but_omits_it_from_plan_one_a_dto(): void
    {
        $record = $this->record([
            'status' => 'expired',
            'artifact_path' => 'org-1/reports/export.csv',
            'artifact_version_id' => 'version-one',
            'artifact_etag' => '"opaque-etag"',
            'artifact_mime' => 'text/csv',
            'artifact_checksum' => str_repeat('f', 64),
            'artifact_size_bytes' => 10,
            'row_count' => 0,
            'ready_at' => new DateTimeImmutable('2026-07-29T10:01:00.123456Z'),
            'expired_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
            'updated_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
            'expires_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
        ]);

        $export = (new ReportExportHydrator)->hydrate($record, 'reused', 1250);

        self::assertSame(ReportExportStatus::EXPIRED, $export->status);
        self::assertNull($export->artifactPath);
        self::assertNull($export->versionId);
        self::assertNull($export->etag);
        self::assertNull($export->checksum);
        self::assertNull($export->rowCount);
    }

    #[DataProvider('invalidMutations')]
    public function test_rejects_malformed_persisted_identity(array $mutation): void
    {
        $this->expectException(ReportContractException::class);

        (new ReportExportHydrator)->hydrate($this->record($mutation), 'reused', 1250);
    }

    public static function invalidMutations(): iterable
    {
        yield 'invalid export hash' => [['export_hash' => 'bad']];
        yield 'noncanonical columns' => [['selected_columns' => ['name', 'amount']]];
        yield 'partial lease' => [[
            'status' => 'running',
            'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
        ]];
        yield 'artifact on queued' => [['artifact_path' => 'org-1/reports/export.csv']];
        yield 'empty retained etag' => [[
            'status' => 'expired',
            'artifact_path' => 'org-1/reports/export.csv',
            'artifact_version_id' => 'version',
            'artifact_etag' => '',
            'artifact_mime' => 'text/csv',
            'artifact_checksum' => str_repeat('f', 64),
            'artifact_size_bytes' => 10,
            'row_count' => 1,
            'ready_at' => new DateTimeImmutable('2026-07-29T10:01:00.123456Z'),
            'expired_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
            'updated_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
            'expires_at' => new DateTimeImmutable('2026-07-29T11:00:00.123456Z'),
        ]];
    }

    private function record(array $overrides = []): ReportExportRecord
    {
        $createdAt = new DateTimeImmutable('2026-07-29T10:00:00.123456Z');
        $attributes = [
            'id' => '01J00000000000000000000001',
            'run_id' => '01J00000000000000000000002',
            'organization_id' => 1,
            'requester_actor_id' => 11,
            'status' => 'queued',
            'export_hash' => str_repeat('e', 64),
            'format' => 'csv',
            'selected_columns' => json_encode(['amount', 'name'], JSON_THROW_ON_ERROR),
            'sort_field' => 'amount',
            'sort_direction' => 'asc',
            'locale' => 'ru',
            'render_timezone' => 'Europe/Moscow',
            'artifact_path' => null,
            'artifact_version_id' => null,
            'artifact_etag' => null,
            'artifact_mime' => null,
            'artifact_checksum' => null,
            'artifact_size_bytes' => null,
            'row_count' => null,
            'error_code' => null,
            'execution_lease_token' => null,
            'execution_lease_expires_at' => null,
            'execution_heartbeat_at' => null,
            'queued_at' => $createdAt,
            'started_at' => null,
            'uploading_at' => null,
            'ready_at' => null,
            'failed_at' => null,
            'cancel_requested_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'expires_at' => $createdAt->modify('+1 day'),
        ];

        $record = new ReportExportRecord;
        $record->setRawAttributes(array_replace($attributes, $overrides), true);

        return $record;
    }
}
