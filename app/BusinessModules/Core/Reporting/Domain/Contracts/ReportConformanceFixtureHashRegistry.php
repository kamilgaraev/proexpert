<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

interface ReportConformanceFixtureHashRegistry
{
    public function fixtureHash(string $code): Sha256Hash;
}
