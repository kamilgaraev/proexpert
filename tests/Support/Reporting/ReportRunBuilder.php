<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus as FreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;

final class ReportRunBuilder
{
    private string $id = '01J00000000000000000000000';
    private string $reportCode = 'report';
    private ReportRunStatus $status = ReportRunStatus::QUEUED;
    private Sha256Hash $definitionHash;
    private string $contractVersion = '1';
    private string $formulaVersion = '1';
    private string $sourceSchemaVersion = '1';
    private string $rendererVersion = '1';
    private Sha256Hash $queryHash;
    private ?Sha256Hash $sourceHash = null;
    private int $progress = 0;
    private ?int $rowCount = null;
    private ?ReportResultMetadata $resultMetadata = null;
    private array $totals = [];
    private ?FreshnessStatus $freshness = null;
    private ?ReportQuality $quality = null;
    private ?ReportProvenance $provenance = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $readyAt = null;
    private DateTimeImmutable $expiresAt;
    private ?DateTimeImmutable $cancelRequestedAt = null;
    private string $httpDisposition = 'created';
    private ?int $pollAfterMs = 1000;

    public function __construct()
    {
        $this->definitionHash = new Sha256Hash(str_repeat('a', 64));
        $this->queryHash = new Sha256Hash(str_repeat('b', 64));
        $this->createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->updatedAt = new DateTimeImmutable('2026-01-01T00:01:00+00:00');
        $this->expiresAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
    }

    public function id(string $value): self { $this->id = $value; return $this; }
    public function reportCode(string $value): self { $this->reportCode = $value; return $this; }
    public function status(ReportRunStatus $value): self { $this->status = $value; return $this; }
    public function definitionHash(Sha256Hash $value): self { $this->definitionHash = $value; return $this; }
    public function contractVersion(string $value): self { $this->contractVersion = $value; return $this; }
    public function formulaVersion(string $value): self { $this->formulaVersion = $value; return $this; }
    public function sourceSchemaVersion(string $value): self { $this->sourceSchemaVersion = $value; return $this; }
    public function rendererVersion(string $value): self { $this->rendererVersion = $value; return $this; }
    public function queryHash(Sha256Hash $value): self { $this->queryHash = $value; return $this; }
    public function sourceHash(?Sha256Hash $value): self { $this->sourceHash = $value; return $this; }
    public function progress(int $value): self { $this->progress = $value; return $this; }
    public function rowCount(?int $value): self { $this->rowCount = $value; return $this; }
    public function resultMetadata(?ReportResultMetadata $value): self { $this->resultMetadata = $value; return $this; }
    public function totals(array $value): self { $this->totals = $value; return $this; }
    public function freshness(?FreshnessStatus $value): self { $this->freshness = $value; return $this; }
    public function quality(?ReportQuality $value): self { $this->quality = $value; return $this; }
    public function provenance(?ReportProvenance $value): self { $this->provenance = $value; return $this; }
    public function createdAt(DateTimeImmutable $value): self { $this->createdAt = $value; return $this; }
    public function updatedAt(DateTimeImmutable $value): self { $this->updatedAt = $value; return $this; }
    public function readyAt(?DateTimeImmutable $value): self { $this->readyAt = $value; return $this; }
    public function expiresAt(DateTimeImmutable $value): self { $this->expiresAt = $value; return $this; }
    public function cancelRequestedAt(?DateTimeImmutable $value): self { $this->cancelRequestedAt = $value; return $this; }
    public function httpDisposition(string $value): self { $this->httpDisposition = $value; return $this; }
    public function pollAfterMs(?int $value): self { $this->pollAfterMs = $value; return $this; }

    public function queued(): ReportRun
    {
        return $this->status(ReportRunStatus::QUEUED)->sourceHash(null)->rowCount(null)->resultMetadata(null)->totals([])->freshness(null)->quality(null)->provenance(null)->readyAt(null)->pollAfterMs(1000)->build();
    }

    public function ready(): ReportRun
    {
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $snapshot = new ReportSnapshotRef('report', 'snapshot', new ReportScope(1, [1], [], [], new DateTimeZone('UTC')), $this->definitionHash, $this->formulaVersion, $sourceHash, $this->updatedAt, null, []);
        $metadata = new ReportResultMetadata($snapshot, 0, $this->updatedAt, null);
        $quality = new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []);
        $provenance = new ReportProvenance('system', [new ReportSourceRef('system', 'report', 'snapshot', 'v1', 'watermark', 0, $sourceHash)], $sourceHash, null);

        return $this->status(ReportRunStatus::READY)->sourceHash($sourceHash)->rowCount(0)->resultMetadata($metadata)->totals([])->freshness(FreshnessStatus::FRESH)->quality($quality)->provenance($provenance)->readyAt($this->updatedAt)->pollAfterMs(null)->build();
    }

    private function build(): ReportRun
    {
        return new ReportRun($this->id, $this->reportCode, $this->status, $this->definitionHash, $this->contractVersion, $this->formulaVersion, $this->sourceSchemaVersion, $this->rendererVersion, $this->queryHash, $this->sourceHash, $this->progress, $this->rowCount, $this->resultMetadata, $this->totals, $this->freshness, $this->quality, $this->provenance, $this->createdAt, $this->updatedAt, $this->readyAt, $this->expiresAt, $this->cancelRequestedAt, $this->httpDisposition, $this->pollAfterMs);
    }
}
