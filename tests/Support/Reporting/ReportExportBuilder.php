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

final class ReportExportBuilder
{
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
    private ?int $pollAfterMs = 1000;

    public function __construct()
    {
        $this->exportHash = new Sha256Hash(str_repeat('d', 64));
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $this->timezone = new DateTimeZone('UTC');
        $this->createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->updatedAt = new DateTimeImmutable('2026-01-01T00:01:00+00:00');
        $this->expiresAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
    }

    public function id(string $value): self { $this->id = $value; return $this; }
    public function runId(string $value): self { $this->runId = $value; return $this; }
    public function status(ReportExportStatus $value): self { $this->status = $value; return $this; }
    public function exportHash(Sha256Hash $value): self { $this->exportHash = $value; return $this; }
    public function format(string $value): self { $this->format = $value; return $this; }
    public function columns(array $value): self { $this->columns = $value; return $this; }
    public function sort(ReportWindowSort $value): self { $this->sort = $value; return $this; }
    public function locale(string $value): self { $this->locale = $value; return $this; }
    public function timezone(DateTimeZone $value): self { $this->timezone = $value; return $this; }
    public function artifactPath(?string $value): self { $this->artifactPath = $value; return $this; }
    public function versionId(?string $value): self { $this->versionId = $value; return $this; }
    public function etag(?string $value): self { $this->etag = $value; return $this; }
    public function checksum(?Sha256Hash $value): self { $this->checksum = $value; return $this; }
    public function sizeBytes(?int $value): self { $this->sizeBytes = $value; return $this; }
    public function rowCount(?int $value): self { $this->rowCount = $value; return $this; }
    public function createdAt(DateTimeImmutable $value): self { $this->createdAt = $value; return $this; }
    public function updatedAt(DateTimeImmutable $value): self { $this->updatedAt = $value; return $this; }
    public function readyAt(?DateTimeImmutable $value): self { $this->readyAt = $value; return $this; }
    public function expiresAt(DateTimeImmutable $value): self { $this->expiresAt = $value; return $this; }
    public function cancelRequestedAt(?DateTimeImmutable $value): self { $this->cancelRequestedAt = $value; return $this; }
    public function httpDisposition(string $value): self { $this->httpDisposition = $value; return $this; }
    public function pollAfterMs(?int $value): self { $this->pollAfterMs = $value; return $this; }

    public function queued(): ReportExport
    {
        return $this->status(ReportExportStatus::QUEUED)->artifactPath(null)->versionId(null)->etag(null)->checksum(null)->sizeBytes(null)->rowCount(null)->readyAt(null)->pollAfterMs(1000)->build();
    }

    public function ready(): ReportExport
    {
        return $this->status(ReportExportStatus::READY)->artifactPath('org-1/reports/report.csv')->versionId('version')->etag('etag')->checksum(new Sha256Hash(str_repeat('e', 64)))->sizeBytes(1)->rowCount(0)->readyAt($this->updatedAt)->pollAfterMs(null)->build();
    }

    private function build(): ReportExport
    {
        return new ReportExport($this->id, $this->runId, $this->status, $this->exportHash, $this->format, $this->columns, $this->sort, $this->locale, $this->timezone, $this->artifactPath, $this->versionId, $this->etag, $this->checksum, $this->sizeBytes, $this->rowCount, $this->createdAt, $this->updatedAt, $this->readyAt, $this->expiresAt, $this->cancelRequestedAt, $this->httpDisposition, $this->pollAfterMs);
    }
}
