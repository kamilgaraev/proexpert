<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentReportCompletedArtifactRecoveryStore implements ReportCompletedArtifactRecoveryStore
{
    public function __construct(
        private ReportExportHydrator $hydrator,
        private int $pollAfterMs = 1000,
    ) {}

    public function claimExpiredUpload(
        ReportExecutionContext $context,
        string $exportId,
        string $newLeaseToken,
        DateTimeImmutable $newLeaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportExport {
        if (
            ! Str::isUuid($newLeaseToken)
            || $newLeaseToken !== strtolower($newLeaseToken)
            || $newLeaseExpiresAt != $occurredAt->modify('+960 seconds')
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
            );
        }

        return DB::transaction(function () use (
            $context,
            $exportId,
            $newLeaseToken,
            $newLeaseExpiresAt,
            $occurredAt,
        ): ReportExport {
            $record = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $context->scope->organizationId)
                ->lockForUpdate()
                ->first();
            $oldExpiry = $record instanceof ReportExportRecord
                ? $this->instant($record->execution_lease_expires_at)
                : null;
            if (
                ! $record instanceof ReportExportRecord
                || $record->status !== ReportExportStatus::UPLOADING->value
                || ! is_string($record->execution_lease_token)
                || hash_equals($record->execution_lease_token, $newLeaseToken)
                || ! $oldExpiry instanceof DateTimeImmutable
                || $oldExpiry > $occurredAt
                || ! $this->sameScope($context, $record)
            ) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_EXPORT_NOT_READY,
                );
            }

            $updated = ReportExportRecord::query()
                ->whereKey($exportId)
                ->where('organization_id', $context->scope->organizationId)
                ->where('status', ReportExportStatus::UPLOADING->value)
                ->where('execution_lease_token', $record->execution_lease_token)
                ->where(
                    'execution_lease_expires_at',
                    '<=',
                    $this->timestamp($occurredAt),
                )
                ->update([
                    'execution_lease_token' => $newLeaseToken,
                    'execution_lease_expires_at' => $this->timestamp($newLeaseExpiresAt),
                    'execution_heartbeat_at' => $this->timestamp($occurredAt),
                    'updated_at' => $this->timestamp($occurredAt),
                ]);
            if ($updated !== 1) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_EXPORT_NOT_READY,
                );
            }

            return $this->hydrator->hydrate(
                $record->fresh(),
                'reused',
                $this->pollAfterMs,
            );
        });
    }

    private function sameScope(
        ReportExecutionContext $context,
        ReportExportRecord $record,
    ): bool {
        return $context->scope->canonicalIdentity() === [
            'organization_id' => (int) $record->organization_id,
            'holding_organization_ids' => array_map(
                'intval',
                (array) $record->scope_holding_organization_ids,
            ),
            'project_ids' => array_map(
                'intval',
                (array) $record->scope_project_ids,
            ),
            'resources' => (array) $record->scope_resources,
            'timezone' => (string) $record->scope_timezone,
        ];
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
