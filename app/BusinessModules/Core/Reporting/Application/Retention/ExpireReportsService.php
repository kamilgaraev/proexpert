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
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ExpireReportsService
{
    public function __construct(
        private ReportTransitionAudit $audit,
        private ReportRunHydrator $runHydrator,
        private ReportExportHydrator $exportHydrator,
    ) {}

    public function expire(int $limit, DateTimeImmutable $occurredAt): array
    {
        $this->assertLimit($limit);
        $candidates = $this->candidates($limit, $occurredAt);
        $summary = [
            'scanned' => count($candidates),
            'transitioned' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($candidates as $candidate) {
            try {
                $transitioned = $candidate['kind'] === 'run'
                    ? $this->expireRun($candidate['id'], $candidate['organization_id'], $occurredAt)
                    : $this->expireExport($candidate['id'], $candidate['organization_id'], $occurredAt);
                $summary[$transitioned ? 'transitioned' : 'skipped']++;
            } catch (Throwable $throwable) {
                $summary['failed']++;
                try {
                    $this->deferCandidate($candidate, $occurredAt);
                } catch (Throwable $deferFailure) {
                    Log::error('report_retention_expiry_deferral_failed', [
                        'kind' => $candidate['kind'],
                        'report_id' => $candidate['id'],
                        'organization_id' => $candidate['organization_id'],
                        'error_type' => $deferFailure::class,
                    ]);
                }
                Log::error('report_retention_expiry_failed', [
                    'kind' => $candidate['kind'],
                    'report_id' => $candidate['id'],
                    'organization_id' => $candidate['organization_id'],
                    'error_type' => $throwable::class,
                ]);
            }
        }

        return $summary;
    }

    private function candidates(int $limit, DateTimeImmutable $occurredAt): array
    {
        $timestamp = $this->timestamp($occurredAt);
        $candidates = [];
        foreach ([
            'run' => ReportRunRecord::query()
                ->where('status', ReportRunStatus::READY->value)
                ->where('expires_at', '<=', $timestamp)
                ->where(static function ($query) use ($timestamp): void {
                    $query->whereNull('retention_next_attempt_at')
                        ->orWhere('retention_next_attempt_at', '<=', $timestamp);
                })
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'organization_id', 'expires_at']),
            'export' => ReportExportRecord::query()
                ->where('status', ReportExportStatus::READY->value)
                ->where('expires_at', '<=', $timestamp)
                ->where(static function ($query) use ($timestamp): void {
                    $query->whereNull('retention_next_attempt_at')
                        ->orWhere('retention_next_attempt_at', '<=', $timestamp);
                })
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'organization_id', 'expires_at']),
        ] as $kind => $records) {
            foreach ($records as $record) {
                $candidates[] = [
                    'kind' => $kind,
                    'id' => (string) $record->id,
                    'organization_id' => (int) $record->organization_id,
                    'expires_at' => $this->instant($record->expires_at),
                ];
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            $instant = strcmp(
                $left['expires_at']->format('U.u'),
                $right['expires_at']->format('U.u'),
            );
            if ($instant !== 0) {
                return $instant;
            }
            $id = strcmp($left['id'], $right['id']);

            return $id !== 0 ? $id : strcmp($left['kind'], $right['kind']);
        });

        return array_slice($candidates, 0, $limit);
    }

    private function expireRun(
        string $runId,
        int $organizationId,
        DateTimeImmutable $occurredAt,
    ): bool {
        return DB::transaction(function () use ($runId, $organizationId, $occurredAt): bool {
            $record = ReportRunRecord::query()
                ->whereKey($runId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportRunRecord
                || $record->status !== ReportRunStatus::READY->value
                || $this->instant($record->expires_at) > $occurredAt) {
                return false;
            }

            $run = $this->runHydrator->hydrate($record, 'reused', 1250);
            if (
                $run->status !== ReportRunStatus::READY
                || $run->sourceHash === null
                || $run->resultMetadata === null
            ) {
                throw new RuntimeException('report_run_retained_identity_invalid');
            }
            $context = $this->context(
                (int) $record->organization_id,
                (int) $record->requester_actor_id,
                "reports:retention:run:{$runId}",
            );
            $expiresAt = $this->instant($record->expires_at);
            $eventKey = "reports:run:{$runId}:expired:".$this->seconds($expiresAt);
            $this->audit->append(
                $eventKey,
                'report.run.expired',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => (string) $record->report_code,
                    'status' => ReportRunStatus::EXPIRED->value,
                    'definition_hash' => (string) $record->definition_hash,
                    'query_hash' => (string) $record->query_hash,
                    'source_hash' => (string) $record->source_hash,
                    'result_hash' => (string) $record->result_hash,
                    'snapshot_id' => (string) $record->snapshot_id,
                    'expired_at' => $this->microseconds($occurredAt),
                ],
                $occurredAt,
            );

            $updated = ReportRunRecord::query()
                ->whereKey($runId)
                ->where('organization_id', $organizationId)
                ->where('status', ReportRunStatus::READY->value)
                ->where('expires_at', '<=', $this->timestamp($occurredAt))
                ->update([
                    'status' => ReportRunStatus::EXPIRED->value,
                    'expired_at' => $this->timestamp($occurredAt),
                    'updated_at' => $this->timestamp($occurredAt),
                    'retention_next_attempt_at' => null,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('report_run_expiry_cas_failed');
            }

            return true;
        });
    }

    private function expireExport(
        string $exportId,
        int $organizationId,
        DateTimeImmutable $occurredAt,
    ): bool {
        return DB::transaction(function () use ($exportId, $organizationId, $occurredAt): bool {
            $record = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportExportRecord
                || $record->status !== ReportExportStatus::READY->value
                || $this->instant($record->expires_at) > $occurredAt) {
                return false;
            }

            $export = $this->exportHydrator->hydrate($record, 'reused', 1250);
            if (
                $export->status !== ReportExportStatus::READY
                || $export->versionId === null
                || $export->checksum === null
            ) {
                throw new RuntimeException('report_export_retained_identity_invalid');
            }
            $context = $this->context(
                (int) $record->organization_id,
                (int) $record->requester_actor_id,
                "reports:retention:export:{$exportId}",
            );
            $expiresAt = $this->instant($record->expires_at);
            $eventKey = "reports:export:{$exportId}:expired:".$this->seconds($expiresAt);
            $this->audit->append(
                $eventKey,
                'report.export.expired',
                $context,
                [
                    'export_id' => $exportId,
                    'run_id' => (string) $record->run_id,
                    'report_code' => (string) $record->report_code,
                    'status' => ReportExportStatus::EXPIRED->value,
                    'format' => (string) $record->format,
                    'version_id' => (string) $record->artifact_version_id,
                    'occurred_at' => $this->microseconds($occurredAt),
                ],
                $occurredAt,
            );

            $updated = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $organizationId)
                ->where('status', ReportExportStatus::READY->value)
                ->where('expires_at', '<=', $this->timestamp($occurredAt))
                ->update([
                    'status' => ReportExportStatus::EXPIRED->value,
                    'expired_at' => $this->timestamp($occurredAt),
                    'updated_at' => $this->timestamp($occurredAt),
                    'retention_next_attempt_at' => null,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('report_export_expiry_cas_failed');
            }

            return true;
        });
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

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_retention_batch_size_invalid');
        }
    }

    /**
     * @param  array{kind:string,id:string,organization_id:int,expires_at:DateTimeImmutable}  $candidate
     */
    private function deferCandidate(array $candidate, DateTimeImmutable $occurredAt): void
    {
        $model = $candidate['kind'] === 'run'
            ? ReportRunRecord::query()
            : ReportExportRecord::query();
        $status = $candidate['kind'] === 'run'
            ? ReportRunStatus::READY->value
            : ReportExportStatus::READY->value;
        $record = $model
            ->whereKey($candidate['id'])
            ->where('organization_id', $candidate['organization_id'])
            ->where('status', $status)
            ->first(['retention_attempt_count']);
        if ($record === null) {
            return;
        }

        $attempt = (int) $record->retention_attempt_count + 1;
        $seconds = min(3600, 2 ** min($attempt, 11));
        $model
            ->whereKey($candidate['id'])
            ->where('organization_id', $candidate['organization_id'])
            ->where('status', $status)
            ->where('retention_attempt_count', $attempt - 1)
            ->update([
                'retention_attempt_count' => $attempt,
                'retention_next_attempt_at' => $this->timestamp($occurredAt->modify("+{$seconds} seconds")),
            ]);
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function seconds(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
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
