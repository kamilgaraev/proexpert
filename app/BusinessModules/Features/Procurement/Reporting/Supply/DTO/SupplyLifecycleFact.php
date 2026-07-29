<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SupplyLifecycleFact
{
    private const TYPES = ['sent', 'confirmed', 'received', 'receipt_reversed', 'returned', 'cancelled'];

    public function __construct(
        public string $type,
        public string $quantity,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public DateTimeImmutable $occurredAt,
        public string $sourceEventId,
        public ?string $reversedEventId = null,
        public ?string $reasonCode = null,
        public ?int $valueMinor = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported supply lifecycle event type.');
        }
        if ($unitDimension === '' || $unitCode === '' || $conversionVersion === '' || $sourceEventId === '') {
            throw new InvalidArgumentException('Supply lifecycle unit and source identity are required.');
        }
        if ($valueMinor !== null && $valueMinor < 0) {
            throw new InvalidArgumentException('Supply lifecycle value cannot be negative.');
        }
    }
}
