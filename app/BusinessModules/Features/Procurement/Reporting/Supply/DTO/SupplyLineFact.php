<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

use DateTimeImmutable;

final readonly class SupplyLineFact
{
    /** @param list<SupplyLifecycleFact> $events */
    public function __construct(
        public string $orderedQuantity,
        public DateTimeImmutable $originalPromiseAt,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public array $events,
        public ?DateTimeImmutable $asOf = null,
        public ?int $orderedValueMinor = null,
        public ?string $currency = null,
        public ?string $valueBasis = null,
    ) {}
}
