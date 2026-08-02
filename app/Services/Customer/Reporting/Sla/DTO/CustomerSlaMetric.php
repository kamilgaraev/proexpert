<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\DTO;

final readonly class CustomerSlaMetric
{
    public function __construct(
        public ?int $firstResponseSeconds,
        public ?int $resolutionSeconds,
        public ?int $openAgingSeconds,
        public ?bool $firstResponseBreached,
        public ?bool $resolutionBreached,
        public bool $actorSideComplete,
    ) {
    }
}
