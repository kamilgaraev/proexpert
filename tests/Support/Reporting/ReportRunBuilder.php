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
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportRunBuilder
{
    private array $explicit = [];
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
    private ?ReportFreshnessStatus $freshness = null;
    private ?ReportQuality $quality = null;
    private ?ReportProvenance $provenance = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $readyAt = null;
    private DateTimeImmutable $expiresAt;
    private ?DateTimeImmutable $cancelRequestedAt = null;
    private string $httpDisposition = 'created';
    private ?int $pollAfterMs = null;

    public function __construct()
    {
        $this->definitionHash = new Sha256Hash(str_repeat('a', 64));
        $this->queryHash = new Sha256Hash(str_repeat('b', 64));
        $this->createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->updatedAt = new DateTimeImmutable('2026-01-01T00:01:00+00:00');
        $this->expiresAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
    }

    public function id(string $value): self { $this->id = $value; return $this->mark('id'); }
    public function reportCode(string $value): self { $this->reportCode = $value; return $this->mark('reportCode'); }
    public function status(ReportRunStatus $value): self { $this->status = $value; return $this->mark('status'); }
    public function definitionHash(Sha256Hash $value): self { $this->definitionHash = $value; return $this->mark('definitionHash'); }
    public function contractVersion(string $value): self { $this->contractVersion = $value; return $this->mark('contractVersion'); }
    public function formulaVersion(string $value): self { $this->formulaVersion = $value; return $this->mark('formulaVersion'); }
    public function sourceSchemaVersion(string $value): self { $this->sourceSchemaVersion = $value; return $this->mark('sourceSchemaVersion'); }
    public function rendererVersion(string $value): self { $this->rendererVersion = $value; return $this->mark('rendererVersion'); }
    public function queryHash(Sha256Hash $value): self { $this->queryHash = $value; return $this->mark('queryHash'); }
    public function sourceHash(?Sha256Hash $value): self { $this->sourceHash = $value; return $this->mark('sourceHash'); }
    public function progress(int $value): self { $this->progress = $value; return $this->mark('progress'); }
    public function rowCount(?int $value): self { $this->rowCount = $value; return $this->mark('rowCount'); }
    public function resultMetadata(?ReportResultMetadata $value): self { $this->resultMetadata = $value; return $this->mark('resultMetadata'); }
    public function totals(array $value): self { $this->totals = $value; return $this->mark('totals'); }
    public function freshness(?ReportFreshnessStatus $value): self { $this->freshness = $value; return $this->mark('freshness'); }
    public function quality(?ReportQuality $value): self { $this->quality = $value; return $this->mark('quality'); }
    public function provenance(?ReportProvenance $value): self { $this->provenance = $value; return $this->mark('provenance'); }
    public function createdAt(DateTimeImmutable $value): self { $this->createdAt = $value; return $this->mark('createdAt'); }
    public function updatedAt(DateTimeImmutable $value): self { $this->updatedAt = $value; return $this->mark('updatedAt'); }
    public function readyAt(?DateTimeImmutable $value): self { $this->readyAt = $value; return $this->mark('readyAt'); }
    public function expiresAt(DateTimeImmutable $value): self { $this->expiresAt = $value; return $this->mark('expiresAt'); }
    public function cancelRequestedAt(?DateTimeImmutable $value): self { $this->cancelRequestedAt = $value; return $this->mark('cancelRequestedAt'); }
    public function httpDisposition(string $value): self { $this->httpDisposition = $value; return $this->mark('httpDisposition'); }
    public function pollAfterMs(?int $value): self { $this->pollAfterMs = $value; return $this->mark('pollAfterMs'); }

    public function queued(): ReportRun
    {
        if ($this->status === ReportRunStatus::READY || $this->hasSealedIdentity()) {
            throw new InvalidArgumentException('report_run_builder_queued_invalid');
        }

        $pollAfterMs = $this->resolveQueuedPollAfterMs();

        return $this->build($this->status, null, null, null, [], null, null, null, null, $pollAfterMs);
    }

    public function ready(): ReportRun
    {
        if (($this->explicit['status'] ?? false) && $this->status !== ReportRunStatus::READY) {
            throw new InvalidArgumentException('report_run_builder_ready_invalid');
        }
        if (($this->explicit['pollAfterMs'] ?? false) && $this->pollAfterMs !== null) {
            throw new InvalidArgumentException('report_run_builder_ready_invalid');
        }

        $sourceHash = $this->resolveRequired('sourceHash', $this->sourceHash, new Sha256Hash(str_repeat('c', 64)));
        $rowCount = $this->resolveRequired('rowCount', $this->rowCount, 0);
        $readyAt = $this->resolveRequired('readyAt', $this->readyAt, $this->updatedAt);
        $metadata = $this->resolveRequired('resultMetadata', $this->resultMetadata, new ReportResultMetadata(new ReportSnapshotRef('report', 'snapshot', new ReportScope(1, [1], [], [], new DateTimeZone('UTC')), $this->definitionHash, $this->formulaVersion, $sourceHash, $readyAt, null, []), $rowCount, $readyAt, null));
        $quality = $this->resolveRequired('quality', $this->quality, new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []));
        $provenance = $this->resolveRequired('provenance', $this->provenance, new ReportProvenance('system', [new ReportSourceRef('system', 'report', 'snapshot', 'v1', 'watermark', $rowCount, $sourceHash)], $sourceHash, null));
        $freshness = $this->resolveRequired('freshness', $this->freshness, ReportFreshnessStatus::FRESH);

        if ($metadata->rowCount !== $rowCount || $metadata->snapshot->sourceHash->value !== $sourceHash->value || $metadata->generatedAt != $readyAt || $provenance->sourceHash->value !== $sourceHash->value) {
            throw new InvalidArgumentException('report_run_builder_ready_invalid');
        }

        return $this->build(ReportRunStatus::READY, $sourceHash, $rowCount, $metadata, $this->totals, $freshness, $quality, $provenance, $readyAt, null);
    }

    private function resolveQueuedPollAfterMs(): ?int
    {
        if (in_array($this->status, [ReportRunStatus::QUEUED, ReportRunStatus::MATERIALIZING], true)) {
            if (($this->explicit['pollAfterMs'] ?? false) && ($this->pollAfterMs === null || $this->pollAfterMs < 1)) {
                throw new InvalidArgumentException('report_run_builder_queued_invalid');
            }

            return $this->pollAfterMs ?? 1000;
        }

        if (($this->explicit['pollAfterMs'] ?? false) && $this->pollAfterMs !== null) {
            throw new InvalidArgumentException('report_run_builder_queued_invalid');
        }

        return null;
    }

    private function hasSealedIdentity(): bool
    {
        return $this->sourceHash !== null || $this->rowCount !== null || $this->resultMetadata !== null || $this->totals !== [] || $this->freshness !== null || $this->quality !== null || $this->provenance !== null || $this->readyAt !== null;
    }

    private function resolveRequired(string $field, mixed $value, mixed $default): mixed
    {
        if (($this->explicit[$field] ?? false) && $value === null) {
            throw new InvalidArgumentException('report_run_builder_ready_invalid');
        }

        return $value ?? $default;
    }

    private function build(ReportRunStatus $status, ?Sha256Hash $sourceHash, ?int $rowCount, ?ReportResultMetadata $resultMetadata, array $totals, ?ReportFreshnessStatus $freshness, ?ReportQuality $quality, ?ReportProvenance $provenance, ?DateTimeImmutable $readyAt, ?int $pollAfterMs): ReportRun
    {
        return new ReportRun($this->id, $this->reportCode, $status, $this->definitionHash, $this->contractVersion, $this->formulaVersion, $this->sourceSchemaVersion, $this->rendererVersion, $this->queryHash, $sourceHash, $this->progress, $rowCount, $resultMetadata, $totals, $freshness, $quality, $provenance, $this->createdAt, $this->updatedAt, $readyAt, $this->expiresAt, $this->cancelRequestedAt, $this->httpDisposition, $pollAfterMs);
    }

    private function mark(string $field): self
    {
        $this->explicit[$field] = true;

        return $this;
    }
}
