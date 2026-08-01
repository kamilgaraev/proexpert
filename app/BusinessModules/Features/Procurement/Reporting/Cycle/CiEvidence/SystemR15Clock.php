<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use DateTimeImmutable;

final readonly class SystemR15Clock implements R15Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
