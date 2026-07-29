<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Retention;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class DeleteExpiredReportArtifactsService
{
    public function __construct(
        private FileService $files,
        private ReportTransitionAudit $audit,
        private ReportExportHydrator $exportHydrator,
        private int $gracePeriodSeconds = 604800,
    ) {
        if ($gracePeriodSeconds < 0 || $gracePeriodSeconds > 2592000) {
            throw new InvalidArgumentException('report_artifact_grace_period_invalid');
        }
    }

    public function delete(int $limit, DateTimeImmutable $occurredAt): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_retention_batch_size_invalid');
        }

        $cutoff = $occurredAt->modify("-{$this->gracePeriodSeconds} seconds");
        $records = ReportExportRecord::query()
            ->where('status', ReportExportStatus::EXPIRED->value)
            ->where('expired_at', '<=', $this->timestamp($cutoff))
            ->orderBy('expired_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $summary = [
            'scanned' => $records->count(),
            'transitioned' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($records as $record) {
            try {
                $eventKey = $this->deletionEventKey(
                    (string) $record->id,
                    (string) $record->artifact_version_id,
                );
                if (ReportAuditIntentRecord::query()->where('event_key', $eventKey)->exists()) {
                    $summary['skipped']++;

                    continue;
                }

                $this->assertDeletable($record, $cutoff);
                $path = (string) $record->artifact_path;
                $versionId = (string) $record->artifact_version_id;
                $this->files->deleteVersion($path, $versionId);
                $recorded = $this->recordDeletion(
                    (string) $record->id,
                    (int) $record->organization_id,
                    $path,
                    $versionId,
                    $cutoff,
                    $occurredAt,
                );
                $summary[$recorded ? 'deleted' : 'skipped']++;
            } catch (Throwable $throwable) {
                $summary['failed']++;
                Log::error('report_artifact_retention_deletion_failed', [
                    'export_id' => (string) $record->id,
                    'organization_id' => (int) $record->organization_id,
                    'error_type' => $throwable::class,
                ]);
            }
        }

        return $summary;
    }

    private function recordDeletion(
        string $exportId,
        int $organizationId,
        string $path,
        string $versionId,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $occurredAt,
    ): bool {
        return DB::transaction(function () use (
            $exportId,
            $organizationId,
            $path,
            $versionId,
            $cutoff,
            $occurredAt,
        ): bool {
            $record = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportExportRecord
                || $record->status !== ReportExportStatus::EXPIRED->value
                || $this->instant($record->expired_at) > $cutoff
                || $record->artifact_path !== $path
                || $record->artifact_version_id !== $versionId) {
                return false;
            }

            $eventKey = $this->deletionEventKey($exportId, $versionId);
            if (ReportAuditIntentRecord::query()->where('event_key', $eventKey)->exists()) {
                return false;
            }

            $this->assertDeletable($record, $cutoff);
            $this->audit->append(
                $eventKey,
                'report.export.artifact_deleted',
                $this->context(
                    (int) $record->organization_id,
                    (int) $record->requester_actor_id,
                    "reports:retention:artifact:{$exportId}",
                ),
                [
                    'export_id' => $exportId,
                    'run_id' => (string) $record->run_id,
                    'report_code' => (string) $record->report_code,
                    'status' => ReportExportStatus::EXPIRED->value,
                    'format' => (string) $record->format,
                    'version_id' => $versionId,
                    'occurred_at' => $this->microseconds($occurredAt),
                ],
                $occurredAt,
            );

            return true;
        });
    }

    private function assertDeletable(ReportExportRecord $record, DateTimeImmutable $cutoff): void
    {
        $export = $this->exportHydrator->hydrate($record, 'reused', 1250);
        $path = $record->artifact_path;
        $versionId = $record->artifact_version_id;
        $prefix = 'org-'.(int) $record->organization_id.'/reports/';
        if (
            $export->status !== ReportExportStatus::EXPIRED
            || $this->instant($record->expired_at) > $cutoff
            || ! is_string($path)
            || ! str_starts_with($path, $prefix)
            || ! is_string($versionId)
            || $versionId === ''
        ) {
            throw new RuntimeException('report_artifact_retention_identity_invalid');
        }
    }

    private function context(int $organizationId, int $actorId, string $correlationId): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);

        return new ReportExecutionContext(
            new ReportActor($actorId, 'active', []),
            $scope,
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'cli',
                $organizationId,
                [$organizationId],
                [],
                [],
                $timezone,
                $correlationId,
                null,
            ),
        );
    }

    private function deletionEventKey(string $exportId, string $versionId): string
    {
        return "reports:export:{$exportId}:artifact-deleted:{$versionId}";
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function microseconds(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        throw new InvalidArgumentException('report_retention_timestamp_invalid');
    }
}
