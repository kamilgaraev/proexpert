<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\DTO;

final readonly class EffectiveLaborRateResolution
{
    public function __construct(
        public int $rateId,
        public string $amount,
        public ?string $currency,
        public string $rateType,
        public int $sourceVersion,
        public string $quality,
    ) {
    }
}
