<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportExportBuilder
{
    private array $explicit = [];
    private string $id = '01J00000000000000000000000';
    private string $runId = '01J00000000000000000000001';
    private ReportExportStatus $status = ReportExportStatus::QUEUED;
    private Sha256Hash $exportHash;
    private string $format = 'csv';
    private array $columns = ['name'];
    private ReportWindowSort $sort;
    private string $locale = 'ru';
    private DateTimeZone $timezone;
    private ?string $artifactPath = null;
    private ?string $versionId = null;
    private ?string $etag = null;
    private ?Sha256Hash $checksum = null;
    private ?int $sizeBytes = null;
    private ?int $rowCount = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $readyAt = null;
    private DateTimeImmutable $expiresAt;
    private ?DateTimeImmutable $cancelRequestedAt = null;
    private string $httpDisposition = 'created';
    private ?int $pollAfterMs = null;

    public function __construct()
    {
        $this->exportHash = new Sha256Hash(str_repeat('d', 64));
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $this->timezone = new DateTimeZone('UTC');
        $this->createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->updatedAt = new DateTimeImmutable('2026-01-01T00:01:00+00:00');
        $this->expiresAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
    }

    public function id(string $value): self { $this->id = $value; return $this->mark('id'); }
    public function runId(string $value): self { $this->runId = $value; return $this->mark('runId'); }
    public function status(ReportExportStatus $value): self { $this->status = $value; return $this->mark('status'); }
    public function exportHash(Sha256Hash $value): self { $this->exportHash = $value; return $this->mark('exportHash'); }
    public function format(string $value): self { $this->format = $value; return $this->mark('format'); }
    public function columns(array $value): self { $this->columns = $value; return $this->mark('columns'); }
    public function sort(ReportWindowSort $value): self { $this->sort = $value; return $this->mark('sort'); }
    public function locale(string $value): self { $this->locale = $value; return $this->mark('locale'); }
    public function timezone(DateTimeZone $value): self { $this->timezone = $value; return $this->mark('timezone'); }
    public function artifactPath(?string $value): self { $this->artifactPath = $value; return $this->mark('artifactPath'); }
    public function versionId(?string $value): self { $this->versionId = $value; return $this->mark('versionId'); }
    public function etag(?string $value): self { $this->etag = $value; return $this->mark('etag'); }
    public function checksum(?Sha256Hash $value): self { $this->checksum = $value; return $this->mark('checksum'); }
    public function sizeBytes(?int $value): self { $this->sizeBytes = $value; return $this->mark('sizeBytes'); }
    public function rowCount(?int $value): self { $this->rowCount = $value; return $this->mark('rowCount'); }
    public function createdAt(DateTimeImmutable $value): self { $this->createdAt = $value; return $this->mark('createdAt'); }
    public function updatedAt(DateTimeImmutable $value): self { $this->updatedAt = $value; return $this->mark('updatedAt'); }
    public function readyAt(?DateTimeImmutable $value): self { $this->readyAt = $value; return $this->mark('readyAt'); }
    public function expiresAt(DateTimeImmutable $value): self { $this->expiresAt = $value; return $this->mark('expiresAt'); }
    public function cancelRequestedAt(?DateTimeImmutable $value): self { $this->cancelRequestedAt = $value; return $this->mark('cancelRequestedAt'); }
    public function httpDisposition(string $value): self { $this->httpDisposition = $value; return $this->mark('httpDisposition'); }
    public function pollAfterMs(?int $value): self { $this->pollAfterMs = $value; return $this->mark('pollAfterMs'); }

    public function queued(): ReportExport
    {
        if ($this->status === ReportExportStatus::READY || $this->hasArtifact()) {
            throw new InvalidArgumentException('report_export_builder_queued_invalid');
        }

        return $this->build($this->status, null, null, null, null, null, null, $this->resolveQueuedPollAfterMs());
    }

    public function ready(): ReportExport
    {
        if (($this->explicit['status'] ?? false) && $this->status !== ReportExportStatus::READY) {
            throw new InvalidArgumentException('report_export_builder_ready_invalid');
        }
        if (($this->explicit['pollAfterMs'] ?? false) && $this->pollAfterMs !== null) {
            throw new InvalidArgumentException('report_export_builder_ready_invalid');
        }

        $artifactPath = $this->resolveRequired('artifactPath', $this->artifactPath, 'org-1/reports/report.csv');
        $versionId = $this->resolveRequired('versionId', $this->versionId, 'version');
        $etag = $this->resolveRequired('etag', $this->etag, 'etag');
        $checksum = $this->resolveRequired('checksum', $this->checksum, new Sha256Hash(str_repeat('e', 64)));
        $sizeBytes = $this->resolveRequired('sizeBytes', $this->sizeBytes, 1);
        $rowCount = $this->resolveRequired('rowCount', $this->rowCount, 0);
        $readyAt = $this->resolveRequired('readyAt', $this->readyAt, $this->updatedAt);

        return $this->build(ReportExportStatus::READY, $artifactPath, $versionId, $etag, $checksum, $sizeBytes, $rowCount, null, $readyAt);
    }

    private function resolveQueuedPollAfterMs(): ?int
    {
        if (in_array($this->status, [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING], true)) {
            if (($this->explicit['pollAfterMs'] ?? false) && ($this->pollAfterMs === null || $this->pollAfterMs < 1)) {
                throw new InvalidArgumentException('report_export_builder_queued_invalid');
            }

            return $this->pollAfterMs ?? 1000;
        }

        if (($this->explicit['pollAfterMs'] ?? false) && $this->pollAfterMs !== null) {
            throw new InvalidArgumentException('report_export_builder_queued_invalid');
        }

        return null;
    }

    private function hasArtifact(): bool
    {
        return $this->artifactPath !== null || $this->versionId !== null || $this->etag !== null || $this->checksum !== null || $this->sizeBytes !== null || $this->rowCount !== null || $this->readyAt !== null;
    }

    private function resolveRequired(string $field, mixed $value, mixed $default): mixed
    {
        if (($this->explicit[$field] ?? false) && $value === null) {
            throw new InvalidArgumentException('report_export_builder_ready_invalid');
        }

        return $value ?? $default;
    }

    private function build(ReportExportStatus $status, ?string $artifactPath, ?string $versionId, ?string $etag, ?Sha256Hash $checksum, ?int $sizeBytes, ?int $rowCount, ?int $pollAfterMs, ?DateTimeImmutable $readyAt = null): ReportExport
    {
        return new ReportExport($this->id, $this->runId, $status, $this->exportHash, $this->format, $this->columns, $this->sort, $this->locale, $this->timezone, $artifactPath, $versionId, $etag, $checksum, $sizeBytes, $rowCount, $this->createdAt, $this->updatedAt, $readyAt, $this->expiresAt, $this->cancelRequestedAt, $this->httpDisposition, $pollAfterMs);
    }

    private function mark(string $field): self
    {
        $this->explicit[$field] = true;

        return $this;
    }
}
