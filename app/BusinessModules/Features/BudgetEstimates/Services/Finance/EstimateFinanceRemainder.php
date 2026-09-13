<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceRemainder
{
    public function __construct(private readonly EstimateFinanceQuery $query, private readonly EstimateFinanceExecution $execution) {}

    public function apply(Estimate $estimate, array $rows): array
    {
        $facts = $this->acceptedFacts($estimate, array_column($rows, 'key'));
        foreach ($rows as &$row) {
            $accepted = $facts[$row['key']] ?? null;
            $row['accepted_basis'] = $accepted;
            $row['condition_basis'] = null;
            if ($accepted === null) {
                continue;
            }
            if ($accepted['quantity'] === null || $accepted['amount_with_vat'] === null || $accepted['amount_without_vat'] === null || $row['amount_without_vat'] === null || $row['amount_with_vat'] === null
                || ! in_array($row['price_basis'], ['with_vat', 'without_vat'], true)) {
                $this->invalid();
            }
            $remaining = FinanceDecimal::subtract($row['quantity'], $accepted['quantity']);
            if (FinanceDecimal::compare($remaining, '0') < 0) {
                $this->invalid();
            }
            $field = $row['price_basis'] === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat';
            $amount = $row['method'] === 'unit'
                ? FinanceDecimal::multiply($remaining, (string) $row['unit_price'])
                : FinanceDecimal::subtract($row[$field], $accepted[$field]);
            if (FinanceDecimal::compare($amount, '0') < 0
                || (FinanceDecimal::compare($remaining, '0') === 0 && FinanceDecimal::compare($amount, '0') !== 0)) {
                $this->invalid();
            }
            $tax = EstimateFinanceTax::calculate($row, $amount);
            $row['condition_basis'] = ['quantity' => $remaining, 'amount_without_vat' => $tax['amount_without_vat'],
                'amount_with_vat' => $tax['amount_with_vat'], 'price_basis' => $row['price_basis'],
                'vat_mode' => $row['vat_mode'], 'vat_rate' => $row['vat_rate']];
            $row['amount_without_vat'] = FinanceDecimal::add($accepted['amount_without_vat'], $tax['amount_without_vat']);
            $row['amount_with_vat'] = FinanceDecimal::add($accepted['amount_with_vat'], $tax['amount_with_vat']);
        }
        unset($row);

        return $rows;
    }

    public function acceptedFacts(Estimate $estimate, array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn (string $key): bool => Str::isUuid($key)));
        if ($keys === []) {
            return [];
        }
        $allocations = DB::table('estimate_finance_allocations')->where('organization_id', $estimate->organization_id)
            ->where('estimate_id', $estimate->id)->whereIn('key', $keys)->get(['key', 'contract_id', 'estimate_item_id', 'resource_id', 'currency'])->keyBy('key');
        if ($allocations->isEmpty()) {
            return [];
        }
        $report = $this->execution->report($estimate, $this->query->contracts($estimate));
        $facts = [];
        foreach ($report['rows'] as $line) {
            $key = $line['allocation_key'];
            $allocation = $allocations->get($key);
            if ($allocation === null) {
                continue;
            }
            $facts[$key] ??= ['quantity' => '0', 'amount_without_vat' => '0', 'amount_with_vat' => '0'];
            $scoped = (int) $allocation->contract_id === (int) $line['contract_id'] && (int) $allocation->estimate_item_id === (int) $line['item_id']
                && $allocation->currency === $line['currency'] && (int) ($allocation->resource_id ?? 0) === (int) ($line['resource_id'] ?? 0);
            foreach (['quantity', 'amount_without_vat', 'amount_with_vat'] as $field) {
                $facts[$key][$field] = ! $scoped || $facts[$key][$field] === null || $line[$field] === null
                    ? null : FinanceDecimal::add($facts[$key][$field], (string) $line[$field]);
            }
        }
        foreach ($facts as &$fact) {
            $fact['amount_without_vat'] = $fact['amount_without_vat'] === null ? null : FinanceDecimal::value($fact['amount_without_vat']);
        }
        unset($fact);

        return $facts;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.remaining_conditions')]);
    }
}
