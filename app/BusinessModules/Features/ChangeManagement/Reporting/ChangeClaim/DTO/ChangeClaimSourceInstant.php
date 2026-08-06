<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class ChangeClaimSourceInstant
{
    private function __construct(
        public DateTimeImmutable $occurredAt,
        public string $effectiveOn,
    ) {}

    public static function from(DateTimeInterface $source): self
    {
        return new self(
            DateTimeImmutable::createFromInterface($source)->setTimezone(new DateTimeZone('UTC')),
            $source->format('Y-m-d'),
        );
    }
}
