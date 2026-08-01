<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Conformance;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class ReportConformanceDrillExpectation
{
    public function __construct(
        public Sha256Hash $fixtureHash,
        public ReportDrillDownCell $cell,
        public Sha256Hash $expectedResultHash,
    ) {}
}
