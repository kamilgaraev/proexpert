<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

use InvalidArgumentException;

final readonly class ContractorComponentSignal
{
    public function __construct(
        public ?string $value,
        public bool $eligible,
    ) {
        if ($value !== null && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('contractor_component_signal_invalid');
        }
    }
}
