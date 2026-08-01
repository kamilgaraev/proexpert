<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use DateTimeImmutable;

interface R15Clock
{
    public function now(): DateTimeImmutable;
}
