<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use Carbon\CarbonImmutable;

final readonly class PlanFactSourceAggregate
{
    public function __construct(
        public string $sourceType,
        public string $month,
        public ?int $budgetArticleId,
        public ?int $responsibilityCenterId,
        public ?int $projectId,
        public ?int $counterpartyId,
        public string $currency,
        public ?string $flowDirection,
        public float $planAmount = 0.0,
        public float $forecastAmount = 0.0,
        public float $actualAmount = 0.0,
        public float $committedAmount = 0.0,
    ) {
    }

    public static function fromDatabaseRow(object $row, string $sourceType): self
    {
        return new self(
            sourceType: $sourceType,
            month: self::monthValue($row->period_month ?? $row->month ?? null),
            budgetArticleId: self::nullableInt($row->budget_article_id ?? null),
            responsibilityCenterId: self::nullableInt($row->responsibility_center_id ?? null),
            projectId: self::nullableInt($row->project_id ?? null),
            counterpartyId: self::nullableInt($row->counterparty_id ?? null),
            currency: self::currency($row->currency ?? null),
            flowDirection: is_string($row->flow_direction ?? null) ? (string) $row->flow_direction : null,
            planAmount: self::money($row->plan_amount ?? 0),
            forecastAmount: self::money($row->forecast_amount ?? 0),
            actualAmount: self::money($row->actual_amount ?? 0),
            committedAmount: self::money($row->committed_amount ?? 0),
        );
    }

    private static function monthValue(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value)->format('Y-m');
        }

        return CarbonImmutable::now()->format('Y-m');
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'RUB';
        }

        return mb_strtoupper($value);
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
