<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportRun
{
    public int $httpStatus;

    public function __construct(
        public string $id,
        public string $reportCode,
        public ReportRunStatus $status,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $rendererVersion,
        public Sha256Hash $queryHash,
        public ?Sha256Hash $sourceHash,
        public int $progress,
        public ?int $rowCount,
        public ?ReportResultMetadata $resultMetadata,
        public array $totals,
        public ?ReportFreshnessStatus $freshness,
        public ?ReportQuality $quality,
        public ?ReportProvenance $provenance,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $readyAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $cancelRequestedAt,
        public string $httpDisposition,
        public ?int $pollAfterMs,
    ) {
        if (!self::isUlid($id) || !self::isSafeIdentifier($reportCode) || !self::hasVersions($contractVersion, $formulaVersion, $sourceSchemaVersion, $rendererVersion) || $progress < 0 || $progress > 100 || !self::hasValidTimestamps($createdAt, $updatedAt, $expiresAt, $readyAt, $cancelRequestedAt) || !in_array($httpDisposition, ['created', 'reused'], true)) {
            throw new InvalidArgumentException('report_run_invalid');
        }

        if ($status === ReportRunStatus::READY) {
            $this->assertReadyIdentity();
        } elseif (!$this->hasEmptySealedIdentity()) {
            throw new InvalidArgumentException('report_run_invalid');
        }

        $this->httpStatus = self::resolveHttpStatus($status, $httpDisposition);

        if (($this->httpStatus === 202 && ($pollAfterMs === null || $pollAfterMs < 1)) || ($this->httpStatus !== 202 && $pollAfterMs !== null)) {
            throw new InvalidArgumentException('report_run_invalid');
        }
    }

    public function responseHeaders(): array
    {
        if ($this->httpStatus === 201) {
            return ['Location' => '/api/v1/admin/reports/runs/'.$this->id];
        }

        if ($this->httpStatus === 202) {
            return ['Retry-After' => max(1, (int) ceil($this->pollAfterMs / 1000))];
        }

        return [];
    }

    private function assertReadyIdentity(): void
    {
        if ($this->sourceHash === null || $this->rowCount === null || $this->resultMetadata === null || $this->freshness === null || $this->quality === null || $this->provenance === null || $this->readyAt === null || $this->rowCount < 0 || $this->resultMetadata->rowCount !== $this->rowCount || $this->resultMetadata->snapshot->sourceHash->value !== $this->sourceHash->value || $this->provenance->sourceHash->value !== $this->sourceHash->value) {
            throw new InvalidArgumentException('report_run_invalid');
        }

        try {
            CanonicalJson::encode($this->totals);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_run_invalid', 0, $exception);
        }
    }

    private function hasEmptySealedIdentity(): bool
    {
        return $this->sourceHash === null && $this->rowCount === null && $this->resultMetadata === null && $this->totals === [] && $this->freshness === null && $this->quality === null && $this->provenance === null && $this->readyAt === null;
    }

    private static function hasVersions(string ...$versions): bool
    {
        foreach ($versions as $version) {
            if (trim($version) === '') {
                return false;
            }
        }

        return true;
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

    private static function resolveHttpStatus(ReportRunStatus $status, string $disposition): int
    {
        if ($status === ReportRunStatus::READY) {
            return $disposition === 'created' ? 201 : 200;
        }

        return in_array($status, [ReportRunStatus::QUEUED, ReportRunStatus::MATERIALIZING], true) ? 202 : 200;
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $value) === 1;
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }
}
