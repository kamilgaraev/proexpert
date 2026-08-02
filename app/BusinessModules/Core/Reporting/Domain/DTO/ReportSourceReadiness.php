<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportSourceReadiness
{
    public function __construct(
        public ReportSourceReadinessStatus $status,
        public int $eligibleCount,
        public int $projectedCount,
        public int $gapCount,
        public int $unknownCount,
        public string $watermark,
        public string $inputHash,
        public string $outputHash,
        public ?CarbonImmutable $verifiedAt,
    ) {
        if (
            min($eligibleCount, $projectedCount, $gapCount, $unknownCount) < 0
            || $projectedCount + $gapCount + $unknownCount !== $eligibleCount
            || trim($watermark) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $outputHash) !== 1
            || ($status === ReportSourceReadinessStatus::READY
                && ($gapCount !== 0 || $unknownCount !== 0 || $verifiedAt === null))
        ) {
            throw new InvalidArgumentException('report_source_readiness_invalid');
        }
    }
}
