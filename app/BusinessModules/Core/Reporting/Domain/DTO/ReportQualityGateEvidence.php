<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportQualityGateEvidence
{
    public function __construct(
        public string $gate,
        public string $ownerPlan,
        public ReportQualityEvidencePhase $phase,
        public ReportQualityEvidenceStatus $status,
        public string $command,
        public int $count,
        public Sha256Hash $schemaHash,
        public string $releaseSha,
        public string $commitSha,
        public DateTimeImmutable $executedAt,
        public ?Sha256Hash $artifactHash,
    ) {
        if (preg_match('/^QG-(0[1-9]|1[0-4])$/', $gate) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $ownerPlan) !== 1
            || trim($command) === '' || $count < 0
            || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $commitSha) !== 1
            || $executedAt->format('Y-m-d\\TH:i:s\\Z') !== $executedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z')) {
            throw new InvalidArgumentException('report_quality_gate_evidence_invalid');
        }
    }
}
