<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO;

use App\Enums\CurrencyCode;
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
        if (CurrencyCode::tryFrom($currency) === null) {
            throw new InvalidArgumentException('project_control_currency_invalid');
        }
    }
}
