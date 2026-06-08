<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use DateTimeImmutable;

final readonly class CashGapForecastContext
{
    public const SCENARIO_OPTIMISTIC = 'optimistic';
    public const SCENARIO_BASE = 'base';
    public const SCENARIO_STRESS = 'stress';

    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public float $openingBalance,
        public string $scenario = self::SCENARIO_BASE,
        public ?CashGapForecastFilters $filters = null,
        public int $stressInflowDelayDays = 7,
        public float $stressInflowProbabilityFactor = 0.75,
        public float $optimisticInflowProbabilityLift = 0.1,
        public int $optimisticInflowAdvanceDays = 0,
    ) {
    }

    public function startDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->periodStart);
    }

    public function endDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->periodEnd);
    }

    public function resolvedFilters(): CashGapForecastFilters
    {
        return $this->filters ?? new CashGapForecastFilters();
    }

    public function period(): array
    {
        return [
            'from' => $this->startDate()->format('Y-m-d'),
            'to' => $this->endDate()->format('Y-m-d'),
        ];
    }
}
