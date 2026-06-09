<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class WipForecastSourceAggregate
{
    /**
     * @param list<string> $sourceTypes
     * @param list<string> $problemFlags
     * @param list<string> $riskFlags
     * @param list<array<string, mixed>> $sourceRowRefs
     */
    public function __construct(
        public ?string $periodMonth,
        public ?int $projectId,
        public ?int $stageId,
        public ?int $contractId,
        public ?int $estimateItemId,
        public string $currency,
        public float $bac,
        public float $plannedValue,
        public ?float $percentComplete,
        public float $earnedValueAmount,
        public float $approvedActValue,
        public float $actualCostAccrual,
        public float $cashOnlyPayments,
        public float $bottomUpEtc,
        public float $forecastRevenue,
        public array $sourceTypes,
        public array $problemFlags,
        public array $riskFlags,
        public string $qualityStatus,
        public int $sourceRowsCount,
        public array $sourceRowRefs = [],
        public ?string $sourceSnapshotHash = null,
    ) {
    }

    public static function fromStoredLine(object $line): self
    {
        $components = is_array($line->formula_components ?? null) ? $line->formula_components : [];
        $group = is_array($line->group_values ?? null) ? $line->group_values : [];

        return new self(
            periodMonth: self::nullableString($line->period ?? null),
            projectId: self::nullableInt($line->project_id ?? null),
            stageId: self::nullableInt($line->stage_id ?? null),
            contractId: self::nullableInt($line->contract_id ?? null),
            estimateItemId: self::nullableInt($line->estimate_item_id ?? null),
            currency: mb_strtoupper((string) ($line->currency ?? 'RUB')),
            bac: round((float) ($line->bac ?? 0), 2),
            plannedValue: round((float) ($line->pv ?? 0), 2),
            percentComplete: self::nullableFloat($line->percent_complete ?? null),
            earnedValueAmount: round((float) ($line->ev ?? 0), 2),
            approvedActValue: round((float) ($components['approved_act_value'] ?? 0), 2),
            actualCostAccrual: round((float) ($line->ac ?? 0), 2),
            cashOnlyPayments: round((float) ($components['cash_only_payments_excluded'] ?? 0), 2),
            bottomUpEtc: round((float) ($line->etc ?? 0), 2),
            forecastRevenue: round((float) ($line->forecast_revenue_at_completion ?? 0), 2),
            sourceTypes: self::stringList($components['source_types'] ?? []),
            problemFlags: self::stringList($line->problem_flags ?? []),
            riskFlags: self::stringList($line->risk_flags ?? []),
            qualityStatus: (string) ($line->quality_status ?? 'actual'),
            sourceRowsCount: max(0, (int) ($components['source_rows_count'] ?? 0)),
            sourceRowRefs: is_array($line->source_row_refs ?? null) ? $line->source_row_refs : [],
            sourceSnapshotHash: self::nullableString($line->source_snapshot_hash ?? null) ?? self::nullableString($group['source_snapshot_hash'] ?? null),
        );
    }

    public static function fromDatabaseRow(object $row): self
    {
        return new self(
            periodMonth: self::nullableString($row->period_month ?? null),
            projectId: self::nullableInt($row->project_id ?? null),
            stageId: self::nullableInt($row->stage_id ?? null),
            contractId: self::nullableInt($row->contract_id ?? null),
            estimateItemId: self::nullableInt($row->estimate_item_id ?? null),
            currency: mb_strtoupper((string) ($row->currency ?? 'RUB')),
            bac: round((float) ($row->bac ?? 0), 2),
            plannedValue: round((float) ($row->pv ?? 0), 2),
            percentComplete: self::nullableFloat($row->percent_complete ?? null),
            earnedValueAmount: round((float) ($row->ev ?? 0), 2),
            approvedActValue: round((float) ($row->approved_act_value ?? 0), 2),
            actualCostAccrual: round((float) ($row->actual_cost_accrual ?? 0), 2),
            cashOnlyPayments: round((float) ($row->cash_only_payments ?? 0), 2),
            bottomUpEtc: round((float) ($row->bottom_up_etc ?? 0), 2),
            forecastRevenue: round((float) ($row->forecast_revenue ?? 0), 2),
            sourceTypes: self::csv((string) ($row->source_types ?? '')),
            problemFlags: self::csv((string) ($row->problem_flags ?? '')),
            riskFlags: self::csv((string) ($row->risk_flags ?? '')),
            qualityStatus: self::qualityStatus(
                self::csv((string) ($row->problem_flags ?? '')),
                self::csv((string) ($row->risk_flags ?? '')),
            ),
            sourceRowsCount: max(0, (int) ($row->source_rows_count ?? 0)),
            sourceRowRefs: [],
            sourceSnapshotHash: self::nullableString($row->source_snapshot_hash ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
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
        if (in_array('source_unavailable', $problemFlags, true)) {
            return 'partial';
        }

        if ($problemFlags !== [] || $riskFlags !== []) {
            return 'attention';
        }

        return 'actual';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== '')));
    }
}
