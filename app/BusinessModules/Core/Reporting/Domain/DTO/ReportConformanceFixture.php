<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportConformanceFixture
{
    public function __construct(
        public Sha256Hash $fixtureHash,
        public int $expectedRowCount,
        public ReportWindowSort $sort,
        public int $pageLimit,
        public int $cursorChunkSize,
        public ReportDrillDownRequest $drillDown,
        public Sha256Hash $expectedTotalsHash,
    ) {
        if ($expectedRowCount < 0
            || $pageLimit < 1
            || $pageLimit > 100
            || $cursorChunkSize < 1
            || $cursorChunkSize > 5000) {
            throw new InvalidArgumentException('report_conformance_fixture_invalid');
        }
    }
}
