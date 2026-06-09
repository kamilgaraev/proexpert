<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class ProjectMarginSourceAggregate
{
    /**
     * @param list<string> $sourceTypes
     * @param list<string> $problemFlags
     * @param list<string> $riskFlags
     */
    public function __construct(
        public ?string $periodMonth,
        public ?int $budgetArticleId,
        public ?int $responsibilityCenterId,
        public ?int $projectId,
        public ?int $contractId,
        public ?int $counterpartyId,
        public string $currency,
        public float $planRevenue,
        public float $planCost,
        public float $forecastRevenue,
        public float $forecastCost,
        public float $actualRevenue,
        public float $actualCost,
        public array $sourceTypes,
        public array $problemFlags,
        public array $riskFlags,
        public string $qualityStatus,
        public int $sourceRowsCount,
    ) {
    }

    public static function fromDatabaseRow(object $row): self
    {
        $problemFlags = self::csv((string) ($row->problem_flags ?? ''));
        $riskFlags = self::csv((string) ($row->risk_flags ?? ''));

        return new self(
            periodMonth: self::nullableString($row->period_month ?? null),
            budgetArticleId: self::nullableInt($row->budget_article_id ?? null),
            responsibilityCenterId: self::nullableInt($row->responsibility_center_id ?? null),
            projectId: self::nullableInt($row->project_id ?? null),
            contractId: self::nullableInt($row->contract_id ?? null),
            counterpartyId: self::nullableInt($row->counterparty_id ?? null),
            currency: mb_strtoupper((string) ($row->currency ?? 'RUB')),
            planRevenue: round((float) ($row->plan_revenue ?? 0), 2),
            planCost: round((float) ($row->plan_cost ?? 0), 2),
            forecastRevenue: round((float) ($row->forecast_revenue ?? 0), 2),
            forecastCost: round((float) ($row->forecast_cost ?? 0), 2),
            actualRevenue: round((float) ($row->actual_revenue ?? 0), 2),
            actualCost: round((float) ($row->actual_cost ?? 0), 2),
            sourceTypes: self::csv((string) ($row->source_types ?? '')),
            problemFlags: $problemFlags,
            riskFlags: $riskFlags,
            qualityStatus: self::qualityStatus($problemFlags, $riskFlags),
            sourceRowsCount: max(0, (int) ($row->source_rows_count ?? 0)),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function csv(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $items = preg_split('/\s*,\s*/', $value) ?: [];

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param list<string> $problemFlags
     * @param list<string> $riskFlags
     */
    private static function qualityStatus(array $problemFlags, array $riskFlags): string
    {
        if (in_array('hidden_by_permissions', $problemFlags, true)) {
            return 'partial';
        }

        if ($problemFlags !== [] || $riskFlags !== []) {
            return 'attention';
        }

        return 'actual';
    }
}
