<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowAggregate;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowMetricRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class IntercompanyContractFlowFormula
{
    public function aggregate(iterable $rows): IntercompanyFlowAggregate
    {
        $currency = null;
        $buckets = ['internal' => 0, 'external' => 0, 'unclassified' => 0];
        $spread = null;

        foreach ($rows as $row) {
            if (! $row instanceof IntercompanyFlowMetricRow) {
                throw new InvalidArgumentException('intercompany_flow_rows_invalid');
            }
            $currency ??= $row->currency;
            if ($currency !== $row->currency) {
                throw new InvalidArgumentException('intercompany_flow_currency_mixed');
            }

            $buckets[$row->flowClass] += $row->amountMinor;
            if ($row->spreadMinor !== null) {
                $spread = ($spread ?? 0) + $row->spreadMinor;
            }
        }

        $currency ??= 'RUB';
        $total = array_sum($buckets);

        return new IntercompanyFlowAggregate(
            currency: $currency,
            internalMinor: $buckets['internal'],
            externalMinor: $buckets['external'],
            unclassifiedMinor: $buckets['unclassified'],
            totalMinor: $total,
            internalShare: $this->share($buckets['internal'], $total),
            externalShare: $this->share($buckets['external'], $total),
            unclassifiedShare: $this->share($buckets['unclassified'], $total),
            linkedSpreadMinor: $spread,
        );
    }

    public function totals(iterable $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (! $row instanceof IntercompanyFlowMetricRow) {
                throw new InvalidArgumentException('intercompany_flow_rows_invalid');
            }
            $grouped[$row->currency][] = $row;
        }

        $totals = [];
        foreach ($grouped as $currency => $currencyRows) {
            $totals[$currency] = $this->aggregate($currencyRows);
        }
        ksort($totals, SORT_STRING);

        return $totals;
    }

    private function share(int $bucket, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        return (string) BigDecimal::of($bucket)
            ->dividedBy($total, 8, RoundingMode::HalfUp);
    }
}
