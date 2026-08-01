<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportQualityEvidenceLedger
{
    public function __construct(
        public string $status,
        public string $releaseSha,
        public Sha256Hash $manifestHash,
        public int $managementIdentityCount,
        public int $publishedCount,
        public int $bindingCount,
        public array $catalogGroups,
        public array $gates,
        public array $prerequisiteEvidenceHashes,
        public DateTimeImmutable $generatedAt,
    ) {
        if (! in_array($status, ['platform_passed', 'release_passed'], true)
            || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1
            || $managementIdentityCount !== 28 || $publishedCount < 0 || $publishedCount > 28
            || $bindingCount < 0 || $bindingCount > 28 || ! array_is_list($catalogGroups)
            || ! array_is_list($gates) || ! array_is_list($prerequisiteEvidenceHashes)) {
            throw new InvalidArgumentException('report_quality_evidence_ledger_invalid');
        }
    }
}
