<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportExport
{
    public int $httpStatus;

    public function __construct(
        public string $id,
        public string $runId,
        public ReportExportStatus $status,
        public Sha256Hash $exportHash,
        public string $format,
        public array $columns,
        public ReportWindowSort $sort,
        public string $locale,
        public DateTimeZone $timezone,
        public ?string $artifactPath,
        public ?string $versionId,
        public ?string $etag,
        public ?Sha256Hash $checksum,
        public ?int $sizeBytes,
        public ?int $rowCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $readyAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $cancelRequestedAt,
        public string $httpDisposition,
        public ?int $pollAfterMs,
    ) {
        if (!self::isUlid($id) || !self::isUlid($runId) || !in_array($format, ['csv', 'xlsx', 'pdf'], true) || !self::hasColumns($columns) || trim($locale) === '' || !self::hasValidTimestamps($createdAt, $updatedAt, $expiresAt, $readyAt, $cancelRequestedAt) || !in_array($httpDisposition, ['created', 'reused'], true)) {
            throw new InvalidArgumentException('report_export_invalid');
        }

        if ($status === ReportExportStatus::READY) {
            $this->assertReadyArtifact();
        } elseif (!$this->hasEmptyArtifact()) {
            throw new InvalidArgumentException('report_export_invalid');
        }

        $this->httpStatus = self::resolveHttpStatus($status, $httpDisposition);

        if (($this->httpStatus === 202 && ($pollAfterMs === null || $pollAfterMs < 1)) || ($this->httpStatus !== 202 && $pollAfterMs !== null)) {
            throw new InvalidArgumentException('report_export_invalid');
        }
    }

    public function responseHeaders(): array
    {
        if ($this->httpStatus === 201) {
            return ['Location' => '/api/v1/admin/reports/exports/'.$this->id];
        }

        if ($this->httpStatus === 202) {
            return ['Retry-After' => max(1, (int) ceil($this->pollAfterMs / 1000))];
        }

        return [];
    }

    private function assertReadyArtifact(): void
    {
        if ($this->artifactPath === null || !self::isPrivateRelativePath($this->artifactPath) || $this->versionId === null || trim($this->versionId) === '' || $this->etag === null || trim($this->etag) === '' || $this->checksum === null || $this->sizeBytes === null || $this->sizeBytes < 1 || $this->rowCount === null || $this->rowCount < 0 || $this->readyAt === null) {
            throw new InvalidArgumentException('report_export_invalid');
        }
    }

    private function hasEmptyArtifact(): bool
    {
        return $this->artifactPath === null && $this->versionId === null && $this->etag === null && $this->checksum === null && $this->sizeBytes === null && $this->rowCount === null && $this->readyAt === null;
    }

    private static function hasColumns(array $columns): bool
    {
        if (!array_is_list($columns) || $columns === []) {
            return false;
        }

        $unique = [];
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1 || isset($unique[$column])) {
                return false;
            }

            $unique[$column] = true;
        }

        return true;
    }

    private static function isPrivateRelativePath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '://') && !preg_match('#(?:^|/)\.\.(?:/|$)#', $path) && !str_contains($path, '\\');
    }

    private static function hasValidTimestamps(DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt, DateTimeImmutable $expiresAt, ?DateTimeImmutable $readyAt, ?DateTimeImmutable $cancelRequestedAt): bool
    {
        if ($createdAt > $updatedAt || $updatedAt > $expiresAt) {
            return false;
        }

        foreach ([$readyAt, $cancelRequestedAt] as $timestamp) {
            if ($timestamp !== null && ($timestamp < $createdAt || $timestamp > $updatedAt)) {
                return false;
            }
        }

        return true;
    }

    private static function resolveHttpStatus(ReportExportStatus $status, string $disposition): int
    {
        if ($status === ReportExportStatus::READY) {
            return $disposition === 'created' ? 201 : 200;
        }

        return in_array($status, [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING], true) ? 202 : 200;
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $value) === 1;
    }
}
