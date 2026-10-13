<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class SpreadsheetCostBlockParser
{
    public function analyze(array $rows, array $mapping, int $headerRow): array
    {
        $numbers = new SpreadsheetNumberParser;
        $positions = [];
        $current = null;
        $footer = [];
        $referenceTotal = 0.0;
        $indices = [];

        foreach ($rows as $rowNumber => $row) {
            $name = trim((string) $this->cell($row, $mapping['name'] ?? null));
            $code = trim((string) $this->cell($row, $mapping['code'] ?? null));
            $position = trim((string) $this->cell($row, $mapping['position_number'] ?? null));
            $label = mb_strtolower($name !== '' ? $name : ($code !== '' ? $code : $position));
            $amount = $numbers->parse($this->cell($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null)))
                ?? $numbers->parse($this->cell($row, $mapping['unit_price'] ?? null));

            if ($rowNumber <= $headerRow) {
                if (preg_match('/^(?:(?:строительных|монтажных|прочих) работ|оборудования|прочие затраты)$/u', $label) === 1
                    && preg_match('/тыс\.?\s*руб/ui', implode(' ', array_map(static fn ($v): string => (string) $v, $row))) === 1) {
                    foreach ($row as $value) {
                        if (is_int($value) || is_float($value)) {
                            $referenceTotal += (float) $value * 1000;
                            break;
                        }
                    }
                }

                continue;
            }

            if (str_starts_with($label, 'раздел') || ($position === '1' && $code === '2' && $name === '3')) {
                $current = null;

                continue;
            }

            $isNumbered = preg_match('/^\d+(?:\.\d+)*\.?$/u', $position) === 1;
            $isUnnumbered = $position === '' && $amount !== null
                && preg_match('/^(?:итого|всего|ндс|на единицу в базисных ценах|накладные расходы|сметная прибыль)/u', $label) !== 1;
            if (($isNumbered || $isUnnumbered) && ($name !== '' || $code !== '')) {
                $current = (int) $rowNumber;
                $quantity = $numbers->parse($this->cell($row, $mapping['quantity'] ?? null));
                $price = $numbers->parse($this->cell($row, $mapping['unit_price'] ?? null));
                $positionTotal = $numbers->parse($this->cell($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null)));
                $positions[$current] = [
                    'base_total' => $positionTotal ?? ($quantity !== null && $price !== null ? $quantity * $price : null),
                    'base_price' => $price,
                    'quantity' => $quantity,
                    'overhead' => null,
                    'profit' => null,
                    'index' => 1.0,
                    'total' => null,
                ];

                continue;
            }

            if (str_contains($label, 'ндс')) {
                $current = null;
                if (preg_match('/ндс\D*([\d,.]+)\s*%/u', $label, $matches) === 1) {
                    $footer['vat_rate'] = $numbers->parse($matches[1]);
                }

                continue;
            }

            if (preg_match('/^(?:итого|всего)\s+(?:по\s+)?(?:смете|разделу)/u', $label) === 1) {
                $current = null;
                if (str_contains($label, 'смете') && $amount !== null) {
                    $vatRate = $footer['vat_rate'] ?? null;
                    $footer['total_estimate_cost'] = $vatRate !== null ? round($amount / (1 + $vatRate / 100), 2) : $amount;
                }

                continue;
            }

            if ($position !== '' || $amount === null) {
                if (str_starts_with($label, 'раздел')) {
                    $current = null;
                }

                continue;
            }

            $charge = str_starts_with($label, 'накладные расходы') ? 'overhead'
                : (str_starts_with($label, 'сметная прибыль') ? 'profit' : null);
            if ($charge !== null) {
                if ($current !== null) {
                    $positions[$current][$charge] = $amount;
                } elseif (isset($footer['total_estimate_cost'])) {
                    $footer[$charge.'_cost'] = $amount;
                }

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($label, 'итого') && str_contains($label, 'накладн') && str_contains($label, 'прибыл')) {
                $positions[$current]['total'] = $amount;
            } elseif (str_starts_with($label, 'всего с учетом')
                && ($positions[$current]['overhead'] !== null || $positions[$current]['profit'] !== null)) {
                $base = $positions[$current]['total'] ?? $positions[$current]['base_total'];
                $index = preg_match('/смр\s*=\s*([\d,.]+)/u', $label, $matches) === 1
                    ? $numbers->parse($matches[1]) : null;
                $index ??= $base !== null && $base != 0 ? $amount / $base : 1.0;
                $positions[$current]['index'] = $index;
                $positions[$current]['total'] = $amount;
                if ($index > 0) {
                    $indices[(string) $index] = $index;
                }
            }
        }

        $commonIndex = count($indices) === 1 ? reset($indices) : null;
        $expected = $footer['total_estimate_cost'] ?? ($referenceTotal > 0 ? $referenceTotal : null);
        $globalCharges = ($footer['overhead_cost'] ?? 0) + ($footer['profit_cost'] ?? 0);
        $candidate = 0.0;
        foreach ($positions as $position) {
            $candidate += $position['total'] ?? (($position['base_total'] ?? 0) * ($commonIndex ?? 1));
        }
        $applyCommonIndex = $commonIndex !== null && $expected !== null
            && min(abs($candidate - $expected), abs($candidate + $globalCharges - $expected)) <= count($positions) * max(1, $commonIndex) * 0.5 + 1;

        $result = [];
        $overhead = 0.0;
        $profit = 0.0;
        foreach ($positions as $rowNumber => $position) {
            $index = $position['total'] === null && $applyCommonIndex ? $commonIndex : $position['index'];
            $hasCharges = $position['overhead'] !== null || $position['profit'] !== null;
            $total = $position['total'] ?? ($position['base_total'] !== null ? round($position['base_total'] * $index, 2) : null);
            if ($position['total'] === null && $hasCharges && $total !== null) {
                $total += ($position['overhead'] ?? 0) + ($position['profit'] ?? 0);
            }
            $oh = $position['overhead'] !== null ? round($position['overhead'] * $index, 2) : null;
            $sp = $position['profit'] !== null ? round($position['profit'] * $index, 2) : null;
            $overhead += $oh ?? 0;
            $profit += $sp ?? 0;
            $result[$rowNumber] = [
                'total' => $total,
                'overhead' => $oh,
                'profit' => $sp,
                'base_price' => $position['base_price'],
                'index' => $index,
                'current_price' => $index != 1 && $total !== null && ($position['quantity'] ?? 0) != 0
                    ? ($total - ($oh ?? 0) - ($sp ?? 0)) / $position['quantity'] : $position['base_price'],
            ];
        }

        $parsedTotal = array_sum(array_column($result, 'total'));
        $tolerance = count($positions) * max(1, $commonIndex ?? 1) * 0.5 + 1;
        $difference = $expected !== null ? min(abs($parsedTotal - $expected), abs($parsedTotal + $globalCharges - $expected)) : 0;
        if ($expected !== null && $difference <= $tolerance) {
            $footer['total_estimate_cost'] = $expected;
            $footer['overhead_cost'] ??= round($overhead, 2);
            $footer['profit_cost'] ??= round($profit, 2);
            $footer['direct_costs'] = round($expected - $footer['overhead_cost'] - $footer['profit_cost'], 2);
        }

        return ['positions' => $result, 'footer' => $footer, 'total_mismatch' => $expected !== null && $difference > $tolerance];
    }

    private function cell(array $row, ?string $column): mixed
    {
        return $column !== null && $column !== '' ? ($row[Coordinate::columnIndexFromString($column) - 1] ?? null) : null;
    }
}
