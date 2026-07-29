<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use InvalidArgumentException;

final readonly class PortfolioLiquidityRow
{
    public string $opening;

    public string $inflow;

    public string $outflow;

    public string $closing;

    public string $gap;

    public string $rowKey;

    public function __construct(
        public string $forecastDate,
        public int $projectId,
        public string $projectName,
        public string $currency,
        public string $scenario,
        string $opening,
        string $inflow,
        string $outflow,
        public int $duplicateSourceCount,
        public string $qualityStatus,
        public array $sourceRefs,
        public array $qualityGaps = [],
        public array $warnings = [],
        public string $reconciliationStatus = 'matched',
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $forecastDate) !== 1
            || $projectId < 0
            || trim($projectName) === ''
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $scenario) !== 1
            || $duplicateSourceCount < 0
            || ! in_array($qualityStatus, ['complete', 'partial', 'invalid'], true)
            || ! array_is_list($sourceRefs)
            || ! array_is_list($qualityGaps)
            || ! array_is_list($warnings)
            || ! in_array($reconciliationStatus, ['matched', 'mismatch', 'not_applicable'], true)) {
            throw new InvalidArgumentException('portfolio_liquidity_row_invalid');
        }

        $this->opening = PortfolioDecimal::money($opening);
        $this->inflow = PortfolioDecimal::money($inflow);
        $this->outflow = PortfolioDecimal::money($outflow);
        $this->closing = PortfolioDecimal::subtract(
            PortfolioDecimal::add($this->opening, $this->inflow),
            $this->outflow,
        );
        $this->gap = PortfolioDecimal::isNegative($this->closing)
            ? PortfolioDecimal::subtract('0.00', $this->closing)
            : '0.00';
        $this->rowKey = implode(':', [$forecastDate, $projectId, $currency, $scenario]);
    }

    public static function recurring(
        array $days,
        int $projectId,
        string $projectName,
        string $currency,
        string $scenario,
        string $opening,
        array $baseSourceRefs,
    ): array {
        $rows = [];
        $nextOpening = PortfolioDecimal::money($opening);

        foreach ($days as $day) {
            if (! is_array($day)) {
                throw new InvalidArgumentException('portfolio_liquidity_days_invalid');
            }

            [$inflow, $inflowRefs, $inflowDuplicates] = self::sumUnique($day['inflows'] ?? []);
            [$outflow, $outflowRefs, $outflowDuplicates] = self::sumUnique($day['outflows'] ?? []);
            $duplicates = $inflowDuplicates + $outflowDuplicates;
            $row = new self(
                forecastDate: (string) ($day['forecast_date'] ?? ''),
                projectId: $projectId,
                projectName: $projectName,
                currency: $currency,
                scenario: $scenario,
                opening: $nextOpening,
                inflow: $inflow,
                outflow: $outflow,
                duplicateSourceCount: $duplicates,
                qualityStatus: $duplicates === 0 ? 'complete' : 'partial',
                sourceRefs: self::uniqueSourceRefs([...$baseSourceRefs, ...$inflowRefs, ...$outflowRefs]),
                qualityGaps: [],
                warnings: $duplicates === 0 ? [] : [['code' => 'DUPLICATE_CASH_FLOW', 'count' => $duplicates]],
                reconciliationStatus: $duplicates === 0 ? 'matched' : 'mismatch',
            );
            $rows[] = $row;
            $nextOpening = $row->closing;
        }

        return $rows;
    }

    public function toArray(): array
    {
        return [
            'row_key' => $this->rowKey,
            'forecast_date' => $this->forecastDate,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'currency' => $this->currency,
            'scenario' => $this->scenario,
            'opening' => $this->opening,
            'inflow' => $this->inflow,
            'outflow' => $this->outflow,
            'closing' => $this->closing,
            'gap' => $this->gap,
            'quality' => $this->qualityStatus,
            'duplicate_source_count' => $this->duplicateSourceCount,
            'source_refs' => $this->sourceRefs,
            'quality_gaps' => $this->qualityGaps,
            'warnings' => $this->warnings,
            'reconciliation_status' => $this->reconciliationStatus,
        ];
    }

    private static function sumUnique(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('portfolio_liquidity_flows_invalid');
        }

        $seen = [];
        $sum = '0.00';
        $duplicates = 0;

        foreach ($items as $item) {
            if (! is_array($item)
                || ! isset($item['key'], $item['amount'])
                || ! is_string($item['key'])
                || trim($item['key']) === '') {
                throw new InvalidArgumentException('portfolio_liquidity_flows_invalid');
            }

            if (isset($seen[$item['key']])) {
                $duplicates++;

                continue;
            }

            $seen[$item['key']] = true;
            $sum = PortfolioDecimal::add($sum, PortfolioDecimal::money($item['amount']));
        }

        return [
            $sum,
            array_map(
                static fn (string $key): array => ['type' => 'payment_document', 'id' => $key],
                array_keys($seen),
            ),
            $duplicates,
        ];
    }

    private static function uniqueSourceRefs(array $sourceRefs): array
    {
        $unique = [];
        foreach ($sourceRefs as $sourceRef) {
            if (! is_array($sourceRef)
                || ! is_string($sourceRef['type'] ?? null)
                || (! is_int($sourceRef['id'] ?? null) && ! is_string($sourceRef['id'] ?? null))) {
                continue;
            }
            $key = $sourceRef['type'].':'.(string) $sourceRef['id'];
            $unique[$key] = [
                'type' => $sourceRef['type'],
                'id' => $sourceRef['id'],
            ];
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
