<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

final class ReportExportHydrator
{
    public function hydrate(ReportExportRecord $record, string $httpDisposition, int $pollAfterMs): ReportExport
    {
        try {
            $status = ReportExportStatus::from($this->string($record->status));
            $this->assertLease($record, $status);
            $this->assertError($record, $status);
            $artifact = $this->artifact($record, $status);
            $columns = $this->columns($record->selected_columns);
            $createdAt = $this->instant($record->created_at);
            $updatedAt = $this->instant($record->updated_at);
            $expiresAt = $this->instant($record->expires_at);
            $cancelRequestedAt = $this->nullableInstant($record->cancel_requested_at);

            if ($status === ReportExportStatus::EXPIRED) {
                $expiredAt = $this->instant($record->expired_at);
                if ($expiredAt < $expiresAt || $updatedAt != $expiredAt) {
                    throw new \InvalidArgumentException('report_export_expiry_invalid');
                }
            }

            $projectArtifact = $status === ReportExportStatus::READY;
            $activePoll = in_array(
                $status,
                [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING],
                true,
            ) ? $pollAfterMs : null;

            return new ReportExport(
                $this->string($record->id),
                $this->string($record->run_id),
                $status,
                new Sha256Hash($this->string($record->export_hash)),
                $this->string($record->format),
                $columns,
                new ReportWindowSort(
                    $this->string($record->sort_field),
                    ReportSortDirection::from($this->string($record->sort_direction)),
                ),
                $this->string($record->locale),
                new DateTimeZone($this->string($record->render_timezone)),
                $projectArtifact ? $artifact['path'] : null,
                $projectArtifact ? $artifact['etag'] : null,
                $projectArtifact ? $artifact['checksum'] : null,
                $projectArtifact ? $artifact['size_bytes'] : null,
                $projectArtifact ? $artifact['row_count'] : null,
                $createdAt,
                $updatedAt,
                $projectArtifact ? $artifact['ready_at'] : null,
                $expiresAt,
                $cancelRequestedAt,
                $httpDisposition,
                $activePoll,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    private function columns(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException('report_export_columns_invalid');
        }

        $canonical = [];
        foreach ($value as $column) {
            if (! is_string($column)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $column) !== 1
                || in_array($column, $canonical, true)) {
                throw new \InvalidArgumentException('report_export_columns_invalid');
            }
            $canonical[] = $column;
        }
        $sorted = $canonical;
        sort($sorted, SORT_STRING);
        if ($canonical !== $sorted) {
            throw new \InvalidArgumentException('report_export_columns_noncanonical');
        }

        return $canonical;
    }

    private function assertLease(ReportExportRecord $record, ReportExportStatus $status): void
    {
        $token = $record->execution_lease_token;
        $expiresAt = $this->nullableInstant($record->execution_lease_expires_at);
        $heartbeatAt = $this->nullableInstant($record->execution_heartbeat_at);
        $active = in_array($status, [ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING], true);

        if ($active) {
            if (! is_string($token)
                || ! Str::isUuid($token)
                || ! $expiresAt instanceof DateTimeImmutable
                || ! $heartbeatAt instanceof DateTimeImmutable
                || $expiresAt <= $heartbeatAt) {
                throw new \InvalidArgumentException('report_export_execution_lease_invalid');
            }

            return;
        }

        if ($token !== null || $expiresAt !== null || $heartbeatAt !== null) {
            throw new \InvalidArgumentException('report_export_execution_lease_invalid');
        }
    }

    private function assertError(ReportExportRecord $record, ReportExportStatus $status): void
    {
        $errorCode = $record->error_code;
        if ($status === ReportExportStatus::FAILED) {
            if (! is_string($errorCode) || ReportErrorCode::tryFrom($errorCode) === null) {
                throw new \InvalidArgumentException('report_export_error_invalid');
            }

            return;
        }

        if ($errorCode !== null) {
            throw new \InvalidArgumentException('report_export_error_invalid');
        }
    }

    private function artifact(ReportExportRecord $record, ReportExportStatus $status): array
    {
        $values = [
            'path' => $record->artifact_path,
            'etag' => $record->artifact_etag,
            'mime' => $record->artifact_mime,
            'checksum' => $record->artifact_checksum,
            'size_bytes' => $record->artifact_size_bytes,
            'row_count' => $record->row_count,
            'ready_at' => $record->ready_at,
        ];
        $retained = in_array($status, [ReportExportStatus::READY, ReportExportStatus::EXPIRED], true);

        if (! $retained) {
            if (array_filter($values, static fn (mixed $value): bool => $value !== null) !== []) {
                throw new \InvalidArgumentException('report_export_artifact_invalid');
            }

            return [
                'path' => null,
                'etag' => null,
                'mime' => null,
                'checksum' => null,
                'size_bytes' => null,
                'row_count' => null,
                'ready_at' => null,
            ];
        }

        $path = $this->string($values['path']);
        $etag = $this->string($values['etag']);
        $mime = $this->string($values['mime']);
        $sizeBytes = $this->integer($values['size_bytes']);
        $rowCount = $this->integer($values['row_count']);
        if (! $this->privatePath($path)
            || ! $this->safeString($etag)
            || ! $this->safeString($mime)
            || $sizeBytes < 1
            || $rowCount < 0) {
            throw new \InvalidArgumentException('report_export_artifact_invalid');
        }

        return [
            'path' => $path,
            'etag' => $etag,
            'mime' => $mime,
            'checksum' => new Sha256Hash($this->string($values['checksum'])),
            'size_bytes' => $sizeBytes,
            'row_count' => $rowCount,
            'ready_at' => $this->instant($values['ready_at']),
        ];
    }

    private function privatePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 1024
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '://')
            && ! preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    private function safeString(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException('report_export_string_invalid');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new \InvalidArgumentException('report_export_integer_invalid');
        }

        return $value;
    }

    private function nullableInstant(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->instant($value);
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        throw new \InvalidArgumentException('report_export_timestamp_invalid');
    }
}
