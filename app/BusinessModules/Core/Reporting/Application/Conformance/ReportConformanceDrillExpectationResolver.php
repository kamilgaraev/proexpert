<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Conformance;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

interface ReportConformanceDrillExpectationResolver
{
    public function resolve(Sha256Hash $fixtureHash): ReportConformanceDrillExpectation;
}
