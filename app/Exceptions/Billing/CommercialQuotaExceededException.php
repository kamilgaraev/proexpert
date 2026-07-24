<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

class CommercialQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limitKey,
        public readonly int|float $used,
        public readonly int|float|null $limit,
        public readonly int|float $delta,
    ) {
        parent::__construct("Commercial quota '{$limitKey}' is exceeded.");
    }
}
