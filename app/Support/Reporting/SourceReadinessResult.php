<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use DateTimeImmutable;
use DomainException;

final readonly class SourceReadinessResult
{
    public function __construct(
        public int $eligibleCount,
        public int $projectedCount,
        public int $gapCount,
        public int $unknownUnitCount,
        public int $invalidVersionCount,
        public int $invalidHashCount,
        public DateTimeImmutable $verifiedAt,
    ) {}

    public function assertReady(string $reportCode): void
    {
        if ($this->eligibleCount !== $this->projectedCount
            || $this->gapCount !== 0
            || $this->unknownUnitCount !== 0
            || $this->invalidVersionCount !== 0
            || $this->invalidHashCount !== 0) {
            throw new DomainException($reportCode.' reporting source is not ready.');
        }
    }
}
