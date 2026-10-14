<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class SpreadsheetCostBlockParser
{
    public function analyze(array $rows, array $mapping, int $headerRow): array
    {
        $numbers = new SpreadsheetNumberParser;
        $positions = [];
        $current = null;
        $footer = [];
        $summaryControls = [];
        $referenceTotal = 0.0;
        $indices = [];
        $isBaseSummary = false;
        $baseControls = [];
        $expensePrecision = null;
        $globalIndex = null;
        $globalTotal = null;
        $indexedPrecisions = [];
        $sectionControls = [];
        $sectionStart = $headerRow + 1;
        $positionControls = [];
        $globalChargeRow = null;

        foreach ($rows as $rowNumber => $row) {
            $name = trim((string) $this->cell($row, $mapping['name'] ?? null));
            $code = trim((string) $this->cell($row, $mapping['code'] ?? null));
            $position = trim((string) $this->cell($row, $mapping['position_number'] ?? null));
            $label = mb_strtolower($name !== '' ? $name : ($code !== '' ? $code : $position));
            $amount = $numbers->parse($this->cell($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null)))
                ?? $numbers->parse($this->cell($row, $mapping['unit_price'] ?? null));
            $rawAmount = $this->cell($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null))
                ?? $this->cell($row, $mapping['unit_price'] ?? null);

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

            if (str_contains($label, 'прямые затраты') && str_contains($label, 'по смете') && str_contains($label, 'базисн')) {
                $isBaseSummary = true;
                $current = null;
                if ($amount !== null) {
                    $baseControls['direct_costs'] = $amount;
                }

                continue;
            }

            if ($isBaseSummary && in_array($label, ['накладные расходы', 'сметная прибыль'], true) && $amount !== null) {
                $baseControls[$label === 'накладные расходы' ? 'overhead_cost' : 'profit_cost'] = $amount;

                continue;
            }

            if ($isBaseSummary && str_starts_with($label, 'всего с учетом')
                && preg_match('/смр\s*=\s*([\d,.]+)/u', $label, $matches) === 1 && $amount !== null) {
                $globalIndex = $numbers->parse($matches[1]);
                $globalTotal = $amount;

                continue;
            }

            if ($isBaseSummary && preg_match('/^итого\s+поз\./u', $label) === 1) {
                $current = null;
                continue;
            }
            if ($isBaseSummary && preg_match('/^итого\s*:?$/u', $label) === 1 && $amount !== null) {
                $baseControls['total_estimate_cost'] = $amount;
                $current = null;
                continue;
            }

            if (str_starts_with($label, 'раздел') || ($position === '1' && $code === '2' && $name === '3')) {
                $current = null;
                $sectionStart = (int) $rowNumber + 1;

                continue;
            }

            if (self::isVatSummaryLabel($label)) {
                $current = null;
                if ($amount !== null) {
                    $footer['vat_amount'] = $amount;
                }
                if (preg_match('/(?:ндс|налог на добавленную стоимость)\D*([\d,.]+)\s*%/u', $label, $matches) === 1) {
                    $footer['vat_rate'] = $numbers->parse($matches[1]);
                }

                continue;
            }

            $isSummary = $this->isSummaryLabel($label);
            $isEstimateSummary = $isSummary && $this->isEstimateSummaryLabel($label);
            $isSectionSummary = $isSummary && $this->isSectionSummaryLabel($label);
            if ($isSummary && !$isEstimateSummary && !$isSectionSummary) {
                $current = null;
                continue;
            }

            $isNumbered = !$isSummary && preg_match('/^\d+(?:\.\d+)*\.?$/u', $position) === 1;
            $isUnnumbered = $position === '' && $amount !== null
                && preg_match('/^(?:итого|всего|на единицу в базисных ценах|накладные расходы|сметная прибыль)/u', $label) !== 1;
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

            if ($isSummary) {
                $current = null;
                if ($isEstimateSummary && $amount !== null) {
                    $vatRate = str_contains($label, 'без ндс') ? null : ($footer['vat_rate'] ?? null);
                    $vatAmount = str_contains($label, 'без ндс') ? null : ($footer['vat_amount'] ?? null);
                    $summaryControls[] = [
                        'amount' => $amount,
                        'vat_rate' => $vatRate,
                        'vat_amount' => $vatAmount,
                        'row_number' => (int) $rowNumber,
                        'charges' => ($footer['overhead_cost'] ?? 0) + ($footer['profit_cost'] ?? 0),
                    ];
                    $footer['total_estimate_cost'] = $vatRate !== null ? round($amount / (1 + $vatRate / 100), 2) : $amount - ($vatAmount ?? 0);
                    if ($vatRate !== null || $vatAmount !== null) {
                        $footer['total_with_vat'] = $amount;
                    }
                } elseif ($isSectionSummary && $amount !== null) {
                    $sectionControls[] = ['amount' => $amount, 'from' => $sectionStart, 'to' => (int) $rowNumber];
                    $sectionStart = (int) $rowNumber + 1;
                }

                continue;
            }

            $charge = str_starts_with($label, 'накладные расходы') ? 'overhead'
                : (str_starts_with($label, 'сметная прибыль') ? 'profit' : null);
            if ($charge !== null) {
                if ($current !== null) {
                    $positions[$current][$charge] = $amount;
                    if ($amount !== null) {
                        $expensePrecision = max($expensePrecision ?? 0, $this->precision($rawAmount));
                    }
                    if (preg_match('/([\d,.]+)\s*%\s*фот\s*\(от\s*([^)]+)\)/u', $label, $rule) === 1) {
                        $rate = $numbers->parse($rule[1]);
                        $basis = $numbers->parse($rule[2]);
                        if ($rate !== null && $basis !== null) {
                            $positions[$current][$charge.'_rule'] = ['rate' => $rate, 'basis' => $basis];
                        }
                    }
                } elseif ($amount !== null && isset($footer['total_estimate_cost'])) {
                    $footer[$charge.'_cost'] = $amount;
                    $footer['global_'.$charge.'_cost'] = $amount;
                    $globalChargeRow = (int) $rowNumber;
                    $footer[$charge.'_cost_declared'] = true;
                }

                continue;
            }

            if ($position !== '' || $amount === null) {
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($label, 'итого') && str_contains($label, 'накладн') && str_contains($label, 'прибыл')) {
                $positionControls[] = [
                    'amount' => $amount,
                    'precision' => $this->precision($rawAmount),
                    'expected' => ($positions[$current]['base_total'] ?? 0) + ($positions[$current]['overhead'] ?? 0) + ($positions[$current]['profit'] ?? 0),
                ];
                $positions[$current]['total'] = $amount;
            } elseif (str_starts_with($label, 'всего с учетом')
                && ($positions[$current]['overhead'] !== null || $positions[$current]['profit'] !== null
                    || isset($positions[$current]['overhead_rule']) || isset($positions[$current]['profit_rule']))) {
                $base = $positions[$current]['total'] ?? $positions[$current]['base_total'];
                $index = preg_match('/смр\s*=\s*([\d,.]+)/u', $label, $matches) === 1
                    ? $numbers->parse($matches[1]) : null;
                $index ??= $base !== null && $base != 0 ? $amount / $base : 1.0;
                $positions[$current]['index'] = $index;
                $positions[$current]['total'] = $amount;
                $indexedPrecisions[] = $this->precision($rawAmount);
                if ($index > 0) {
                    $indices[(string) $index] = $index;
                }
            }
        }

        if ($globalIndex !== null && $globalIndex > 0 && $globalTotal !== null) {
            $amountPrecision = $indexedPrecisions !== [] ? max($indexedPrecisions) : 2;
            $expensePrecision ??= $amountPrecision;
            try {
                $calculated = (new SpreadsheetAggregateCostCalculator)->calculate($positions, $globalIndex, $expensePrecision, $amountPrecision);
            } catch (\InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'spreadsheet_expense_rule_missing') {
                    throw $exception;
                }

                return ['positions' => [], 'footer' => [], 'total_mismatch' => true, 'unsupported_rule' => true];
            }
            $calculatedTotal = BigDecimal::of((string) $calculated['totals']['total_estimate_cost']);
            $mismatch = $calculatedTotal->compareTo((string) $globalTotal) !== 0;
            foreach ($baseControls as $component => $control) {
                $baseAmount = $component === 'total_estimate_cost'
                    ? array_sum($calculated['base_totals']) : $calculated['base_totals'][$component];
                $mismatch = $mismatch || BigDecimal::of((string) $baseAmount)->compareTo((string) $control) !== 0;
            }
            if (isset($footer['total_estimate_cost'])) {
                $mismatch = $mismatch || $calculatedTotal->compareTo((string) $footer['total_estimate_cost']) !== 0;
            }
            foreach ($summaryControls as $control) {
                $controlVat = $control['vat_rate'] !== null
                    ? $calculatedTotal->multipliedBy((string) $control['vat_rate'])->multipliedBy('0.01')->toScale(2, RoundingMode::HalfUp)
                    : BigDecimal::of((string) ($control['vat_amount'] ?? 0));
                $mismatch = $mismatch || $calculatedTotal->plus($controlVat)->compareTo((string) $control['amount']) !== 0;
            }
            if (isset($footer['vat_rate'])) {
                $vat = $calculatedTotal->multipliedBy((string) $footer['vat_rate'])->multipliedBy('0.01')->toScale(2, RoundingMode::HalfUp);
                if (isset($footer['vat_amount'])) {
                    $mismatch = $mismatch || $vat->compareTo((string) $footer['vat_amount']) !== 0;
                }
                if (isset($footer['total_with_vat'])) {
                    $mismatch = $mismatch || $calculatedTotal->plus($vat)->compareTo((string) $footer['total_with_vat']) !== 0;
                }
            }
            $footer = $calculated['totals'] + array_intersect_key($footer, ['vat_rate' => true, 'vat_amount' => true, 'total_with_vat' => true]);
            foreach (['overhead_cost', 'profit_cost'] as $component) {
                if (array_key_exists($component, $baseControls)) {
                    $footer[$component.'_declared'] = true;
                }
            }
            $footer['calculation'] = [
                'expenses_scope' => 'rate_group',
                'index_scope' => 'estimate',
                'expense_precision' => $expensePrecision,
                'amount_precision' => $amountPrecision,
                'price_index' => $globalIndex,
                'base_totals' => $calculated['base_totals'],
                'declared_total' => $globalTotal,
            ];

            $mismatch = $mismatch || $this->controlsMismatch($calculated['positions'], $sectionControls, $positionControls);

            return ['positions' => $calculated['positions'], 'footer' => $footer, 'total_mismatch' => $mismatch];
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
        $tolerance = 0.004;
        $difference = $expected !== null ? min(abs($parsedTotal - $expected), abs($parsedTotal + $globalCharges - $expected)) : 0;
        if ($expected !== null && $difference <= $tolerance) {
            $footer['total_estimate_cost'] = $expected;
            $footer['overhead_cost'] ??= round($overhead, 2);
            $footer['profit_cost'] ??= round($profit, 2);
            $footer['direct_costs'] = round($expected - $footer['overhead_cost'] - $footer['profit_cost'], 2);
        }

        $mismatch = $expected !== null && $difference > $tolerance;
        foreach ($summaryControls as $control) {
            $net = BigDecimal::zero();
            foreach ($result as $rowNumber => $position) {
                if ($rowNumber < $control['row_number'] && $position['total'] !== null) {
                    $net = $net->plus((string) $position['total']);
                }
            }
            $net = $net->plus((string) $control['charges'])->toScale(2, RoundingMode::HalfUp);
            $vat = $control['vat_rate'] !== null
                ? $net->multipliedBy((string) $control['vat_rate'])->multipliedBy('0.01')->toScale(2, RoundingMode::HalfUp)
                : BigDecimal::of((string) ($control['vat_amount'] ?? 0));
            $mismatch = $mismatch || $net->plus($vat)->compareTo((string) $control['amount']) !== 0;
        }
        if (isset($footer['vat_rate'], $footer['vat_amount'])) {
            $net = BigDecimal::of((string) $parsedTotal)->plus((string) $globalCharges);
            $vat = $net->multipliedBy((string) $footer['vat_rate'])->multipliedBy('0.01')->toScale(2, RoundingMode::HalfUp);
            $mismatch = $mismatch || $vat->compareTo((string) $footer['vat_amount']) !== 0;
        }
        $mismatch = $mismatch || $this->controlsMismatch($result, $sectionControls, $positionControls);
        if ($globalChargeRow !== null) {
            $chargesConfirmed = false;
            foreach ($summaryControls as $control) {
                if ($control['row_number'] > $globalChargeRow) {
                    $chargesConfirmed = true;
                }
            }
            $mismatch = $mismatch || !$chargesConfirmed;
        }
        if (array_key_exists('global_overhead_cost', $footer)) {
            $footer['overhead_cost'] = round($overhead + $footer['global_overhead_cost'], 2);
        }
        if (array_key_exists('global_profit_cost', $footer)) {
            $footer['profit_cost'] = round($profit + $footer['global_profit_cost'], 2);
        }
        if (isset($footer['total_estimate_cost'])) {
            $footer['direct_costs'] = round($footer['total_estimate_cost'] - ($footer['overhead_cost'] ?? $overhead) - ($footer['profit_cost'] ?? $profit), 2);
        }

        return ['positions' => $result, 'footer' => $footer, 'total_mismatch' => $mismatch];
    }

    public static function isVatSummaryLabel(string $label): bool
    {
        return preg_match('/^(?:(?:в\s+том\s+числе|в\s+т\.\s*ч\.|сумма|включая)\s+)?(?:ндс|налог\s+на\s+добавленную\s+стоимость)(?:\s*[:,]?\s*\(?\s*\d+(?:[.,]\d+)?\s*%\s*\)?)?\s*:?$/iu', $label) === 1;
    }

    private function isSummaryLabel(string $label): bool
    {
        return preg_match('/^(?:итого|всего)(?:\s|:|$)/u', $label) === 1
            && !str_contains($label, 'накладн') && !str_contains($label, 'прибыл') && !str_contains($label, 'на физобъем')
            && (!str_contains($label, 'с учетом') || preg_match('/с учетом\s+ндс/u', $label) === 1);
    }

    private function isEstimateSummaryLabel(string $label): bool
    {
        $label = rtrim($label, ": \t\n\r");

        return preg_match('/^(?:итого|всего)(?:\s+(?:(?:по\s+)?(?:смете|акту|документу)(?:\s+(?:без\s+ндс|с\s+(?:учетом\s+)?ндс|в\s+(?:текущих|базисных)\s+ценах))?|без\s+ндс|с\s+(?:учетом\s+)?ндс|к\s+оплате))?$/u', $label) === 1
            || $label === 'всего работ и затрат, включаемых в стоимость работ';
    }

    private function isSectionSummaryLabel(string $label): bool
    {
        return preg_match('/^(?:итого|всего)\s+(?:по\s+)?раздел(?:у|а)?(?:\s|:|$)/u', $label) === 1;
    }

    private function controlsMismatch(array $positions, array $sectionControls, array $positionControls): bool
    {
        foreach ($positionControls as $control) {
            if (BigDecimal::of((string) $control['expected'])->toScale($control['precision'], RoundingMode::HalfUp)->compareTo((string) $control['amount']) !== 0) {
                return true;
            }
        }
        foreach ($sectionControls as $control) {
            $total = BigDecimal::zero();
            foreach ($positions as $rowNumber => $position) {
                if ($rowNumber >= $control['from'] && $rowNumber < $control['to'] && $position['total'] !== null) {
                    $total = $total->plus((string) $position['total']);
                }
            }
            if ($total->toScale(2, RoundingMode::HalfUp)->compareTo((string) $control['amount']) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function cell(array $row, ?string $column): mixed
    {
        return $column !== null && $column !== '' ? ($row[Coordinate::columnIndexFromString($column) - 1] ?? null) : null;
    }

    private function precision(mixed $amount): int
    {
        $value = (new SpreadsheetNumberParser)->parse($amount);

        return $value !== null && BigDecimal::of((string) $value)->strippedOfTrailingZeros()->getScale() <= 0 ? 0 : 2;
    }
}
