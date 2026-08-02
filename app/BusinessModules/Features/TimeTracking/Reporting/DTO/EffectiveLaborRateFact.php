<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\DTO;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EffectiveLaborRateFact
{
    public function __construct(
        public int $rateId,
        public int $organizationId,
        public int $employeeId,
        public string $amount,
        public ?string $currency,
        public string $rateType,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validToExclusive,
        public int $sourceVersion,
    ) {
        if (min($rateId, $organizationId, $employeeId, $sourceVersion) < 1
            || BigDecimal::of($amount)->isLessThan(BigDecimal::zero())
            || ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1)
            || ($validToExclusive !== null && $validToExclusive <= $validFrom)) {
            throw new InvalidArgumentException('effective_labor_rate_fact_invalid');
        }
    }

    public function identity(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->employeeId,
            $this->amount,
            $this->currency ?? 'none',
            $this->rateType,
            $this->validFrom->format('Y-m-d'),
            $this->validToExclusive?->format('Y-m-d') ?? 'open',
            $this->sourceVersion,
        ]);
    }
}
