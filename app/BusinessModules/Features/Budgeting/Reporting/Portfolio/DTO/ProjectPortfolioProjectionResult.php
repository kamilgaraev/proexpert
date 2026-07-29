<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use InvalidArgumentException;

final readonly class ProjectPortfolioProjectionResult
{
    public array $rows;
    public array $totalsByCurrency;

    private array $payload;

    private function __construct(array $rows, array $totalsByCurrency, array $payload)
    {
        foreach ($rows as $row) {
            if (!$row instanceof ProjectPortfolioHealthRow) {
                throw new InvalidArgumentException('project_portfolio_projection_rows_invalid');
            }
        }

        $this->rows = array_values($rows);
        $this->totalsByCurrency = $totalsByCurrency;
        $this->payload = $payload;
    }

    public static function fromRows(array $rows, string $generatedAt, int $itemLimit): self
    {
        $totals = self::totals($rows);

        return new self($rows, $totals, [
            'available' => true,
            'summary' => ['by_currency' => $totals],
            'items' => array_map(
                static fn (ProjectPortfolioHealthRow $row): array => $row->toArray(),
                array_slice($rows, 0, $itemLimit),
            ),
            'meta' => [
                'generated_at' => $generatedAt,
                'item_limit' => $itemLimit,
                'source_reports' => [],
            ],
        ]);
    }

    public static function fromAggregator(array $rows, array $payload): self
    {
        $asOf = (string) ($payload['meta']['generated_at'] ?? '');
        $typedRows = array_map(
            static fn (array $row): ProjectPortfolioHealthRow => ProjectPortfolioHealthRow::fromLegacy($row, $asOf),
            $rows,
        );

        return new self($typedRows, self::totals($typedRows), $payload);
    }

    public function row(int $projectId, string $currency): ProjectPortfolioHealthRow
    {
        foreach ($this->rows as $row) {
            if ($row->projectId === $projectId && $row->currency === $currency) {
                return $row;
            }
        }

        throw new InvalidArgumentException('project_portfolio_projection_row_missing');
    }

    public function toArray(): array
    {
        return $this->payload;
    }

    private static function totals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            if (!$row instanceof ProjectPortfolioHealthRow) {
                throw new InvalidArgumentException('project_portfolio_projection_rows_invalid');
            }

            $currency = $row->currency;
            $totals[$currency] ??= [
                'revenue' => '0.00',
                'cost' => '0.00',
                'margin' => '0.00',
                'margin_percent' => null,
                'wip' => '0.00',
                'ftc' => '0.00',
                'eac' => '0.00',
                'ctc' => '0.00',
            ];

            foreach (['revenue', 'cost', 'margin', 'wip', 'ftc', 'eac', 'ctc'] as $metric) {
                $totals[$currency][$metric] = PortfolioDecimal::add($totals[$currency][$metric], $row->{$metric});
            }
        }

        foreach ($totals as &$currencyTotals) {
            $currencyTotals['margin_percent'] = PortfolioDecimal::percent(
                $currencyTotals['margin'],
                $currencyTotals['revenue'],
            );
        }
        unset($currencyTotals);
        ksort($totals, SORT_STRING);

        return $totals;
    }
}
