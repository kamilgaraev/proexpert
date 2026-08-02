<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use DateTimeImmutable;

final readonly class CashGapForecastContext
{
    public const SCENARIO_OPTIMISTIC = 'optimistic';

    public const SCENARIO_BASE = 'base';

    public const SCENARIO_PESSIMISTIC = 'pessimistic';

    public const SCENARIO_STRESS = 'stress';

    public const SCENARIO_CUSTOM = 'custom';

    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        string|int|float $openingBalance,
        public string $scenario = self::SCENARIO_BASE,
        public ?CashGapForecastFilters $filters = null,
        public int $stressInflowDelayDays = 7,
        string|int $stressInflowProbabilityFactor = '0.75',
        string|int $optimisticInflowProbabilityLift = '0.1',
        public int $optimisticInflowAdvanceDays = 0,
        public array $scenarioAdjustments = [],
    ) {
        $this->openingBalance = self::money($openingBalance);
        $this->stressInflowProbabilityFactor = self::probability($stressInflowProbabilityFactor);
        $this->optimisticInflowProbabilityLift = self::probability($optimisticInflowProbabilityLift);
    }

    public string $openingBalance;

    public string $stressInflowProbabilityFactor;

    public string $optimisticInflowProbabilityLift;

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
        return $this->filters ?? new CashGapForecastFilters;
    }

    public function period(): array
    {
        return [
            'from' => $this->startDate()->format('Y-m-d'),
            'to' => $this->endDate()->format('Y-m-d'),
        ];
    }

    private static function money(string|int|float $amount): string
    {
        if (is_float($amount)) {
            $amount = rtrim(rtrim(sprintf('%.14F', $amount), '0'), '.');
        }

        return PortfolioDecimal::money($amount);
    }

    private static function probability(string|int $probability): string
    {
        return PortfolioDecimal::ratio($probability);
    }
}
