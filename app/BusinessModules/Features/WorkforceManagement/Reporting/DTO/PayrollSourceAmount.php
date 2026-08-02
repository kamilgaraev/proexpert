<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class PayrollSourceAmount
{
    public function __construct(
        public string $rate,
        public string $currency,
        public string $amount,
    ) {
    }
}
