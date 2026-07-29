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
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class DeleteExpiredReportArtifactsService
{
    private const DELETION_LEASE_SECONDS = 300;

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
            ->whereNull('artifact_deleted_at')
            ->where(static function ($query) use ($occurredAt): void {
                $query
                    ->whereNull('artifact_deletion_lease_token')
                    ->orWhere('artifact_deletion_lease_expires_at', '<=', $occurredAt->format('Y-m-d H:i:s.uP'));
            })
            ->orderBy('expired_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'organization_id']);
        $summary = [
            'scanned' => $records->count(),
            'transitioned' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($records as $record) {
            try {
                $deleted = $this->deleteArtifact(
                    (string) $record->id,
                    (int) $record->organization_id,
                    $cutoff,
                    $occurredAt,
                );
                $summary[$deleted ? 'deleted' : 'skipped']++;
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

    private function deleteArtifact(
        string $exportId,
        int $organizationId,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $occurredAt,
    ): bool {
        $leaseToken = strtolower((string) Str::uuid());
        $claim = $this->claimArtifact(
            $exportId,
            $organizationId,
            $cutoff,
            $occurredAt,
            $leaseToken,
        );
        if ($claim === null) {
            return false;
        }

        try {
            $this->files->deleteVersion($claim['path'], $claim['version_id']);
        } catch (Throwable $throwable) {
            $this->releaseClaim($exportId, $organizationId, $leaseToken);

            throw $throwable;
        }

        try {
            return $this->finalizeDeletion(
                $exportId,
                $organizationId,
                $leaseToken,
                $claim,
                $occurredAt,
            );
        } catch (Throwable $throwable) {
            $this->releaseClaim($exportId, $organizationId, $leaseToken);

            throw $throwable;
        }
    }

    /**
     * @return array{path:string,version_id:string,run_id:string,report_code:string,format:string,actor_id:int}|null
     */
    private function claimArtifact(
        string $exportId,
        int $organizationId,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $occurredAt,
        string $leaseToken,
    ): ?array {
        return DB::transaction(function () use (
            $exportId,
            $organizationId,
            $cutoff,
            $occurredAt,
            $leaseToken,
        ): ?array {
            $record = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportExportRecord
                || $record->status !== ReportExportStatus::EXPIRED->value
                || $this->instant($record->expired_at) > $cutoff
                || $record->artifact_deleted_at !== null
                || (
                    $record->artifact_deletion_lease_token !== null
                    && $this->instant($record->artifact_deletion_lease_expires_at) > $occurredAt
                )) {
                return null;
            }

            $this->assertDeletable($record, $cutoff);
            $path = (string) $record->artifact_path;
            $versionId = (string) $record->artifact_version_id;
            $eventKey = $this->deletionEventKey($exportId, $versionId);
            if (ReportAuditIntentRecord::query()->where('event_key', $eventKey)->exists()) {
                $this->markAlreadyRecordedDeletion($record, $occurredAt);

                return null;
            }

            $leaseExpiresAt = $occurredAt->modify('+'.self::DELETION_LEASE_SECONDS.' seconds');
            $updated = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->whereNull('artifact_deleted_at')
                ->where(static function ($query) use ($occurredAt): void {
                    $query
                        ->whereNull('artifact_deletion_lease_token')
                        ->orWhere('artifact_deletion_lease_expires_at', '<=', $occurredAt->format('Y-m-d H:i:s.uP'));
                })
                ->update([
                    'artifact_deletion_lease_token' => $leaseToken,
                    'artifact_deletion_lease_expires_at' => $this->timestamp($leaseExpiresAt),
                    'artifact_deletion_attempt_count' => DB::raw('artifact_deletion_attempt_count + 1'),
                ]);
            if ($updated !== 1) {
                return null;
            }

            return [
                'path' => $path,
                'version_id' => $versionId,
                'run_id' => (string) $record->run_id,
                'report_code' => (string) $record->report_code,
                'format' => (string) $record->format,
                'actor_id' => (int) $record->requester_actor_id,
            ];
        });
    }

    /**
     * @param  array{path:string,version_id:string,run_id:string,report_code:string,format:string,actor_id:int}  $claim
     */
    private function finalizeDeletion(
        string $exportId,
        int $organizationId,
        string $leaseToken,
        array $claim,
        DateTimeImmutable $occurredAt,
    ): bool {
        return DB::transaction(function () use (
            $exportId,
            $organizationId,
            $leaseToken,
            $claim,
            $occurredAt,
        ): bool {
            $record = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportExportRecord
                || $record->status !== ReportExportStatus::EXPIRED->value
                || $record->artifact_deleted_at !== null
                || $record->artifact_deletion_lease_token !== $leaseToken
                || $record->artifact_path !== $claim['path']
                || $record->artifact_version_id !== $claim['version_id']) {
                return false;
            }

            $eventKey = $this->deletionEventKey($exportId, $claim['version_id']);
            $this->audit->append(
                $eventKey,
                'report.export.artifact_deleted',
                $this->context(
                    $organizationId,
                    $claim['actor_id'],
                    "reports:retention:artifact:{$exportId}",
                ),
                [
                    'export_id' => $exportId,
                    'run_id' => $claim['run_id'],
                    'report_code' => $claim['report_code'],
                    'status' => ReportExportStatus::EXPIRED->value,
                    'format' => $claim['format'],
                    'version_id' => $claim['version_id'],
                    'occurred_at' => $this->microseconds($occurredAt),
                ],
                $occurredAt,
            );

            return ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->where('artifact_deletion_lease_token', $leaseToken)
                ->whereNull('artifact_deleted_at')
                ->update([
                    'artifact_deleted_at' => $this->timestamp($occurredAt),
                    'artifact_deletion_lease_token' => null,
                    'artifact_deletion_lease_expires_at' => null,
                ]) === 1;
        });
    }

    private function releaseClaim(
        string $exportId,
        int $organizationId,
        string $leaseToken,
    ): void {
        ReportExportRecord::query()
            ->whereKey($exportId)
            ->where('organization_id', $organizationId)
            ->where('artifact_deletion_lease_token', $leaseToken)
            ->whereNull('artifact_deleted_at')
            ->update([
                'artifact_deletion_lease_token' => null,
                'artifact_deletion_lease_expires_at' => null,
            ]);
    }

    private function markAlreadyRecordedDeletion(
        ReportExportRecord $record,
        DateTimeImmutable $occurredAt,
    ): void {
        ReportExportRecord::query()
            ->whereKey($record->id)
            ->where('organization_id', $record->organization_id)
            ->whereNull('artifact_deleted_at')
            ->update([
                'artifact_deleted_at' => $this->timestamp($occurredAt),
                'artifact_deletion_lease_token' => null,
                'artifact_deletion_lease_expires_at' => null,
            ]);
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
