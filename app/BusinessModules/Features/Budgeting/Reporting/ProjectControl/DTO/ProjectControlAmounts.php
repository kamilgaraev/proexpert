<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO;

use InvalidArgumentException;

final readonly class ProjectControlAmounts
{
    public function __construct(
        public int $bacMinor,
        public int $pvMinor,
        public int $evMinor,
        public int $acMinor,
        public ?int $approvedEtcMinor,
        public string $currency,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('project_control_currency_invalid');
        }
    }
}
