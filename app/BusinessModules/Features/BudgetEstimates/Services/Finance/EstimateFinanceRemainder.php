<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceRemainder
{
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
            if ($accepted['amount_without_vat'] === null || $row['amount_without_vat'] === null || $row['amount_with_vat'] === null
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
        if ($keys === []) {
            return [];
        }
        $lines = DB::table('performance_act_lines as lines')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'lines.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->where('contracts.organization_id', $estimate->organization_id)->where('acts.project_id', $estimate->project_id)
            ->whereNull('acts.annulled_at')->whereIn('acts.status', ['approved', 'signed'])
            ->where('lines.basis_snapshot->basis_type', 'contract_conditions')
            ->whereIn('lines.basis_snapshot->allocation_key', $keys)
            ->select(['lines.quantity', 'lines.amount', 'lines.basis_snapshot'])->get();
        $facts = [];
        foreach ($lines as $line) {
            $snapshot = json_decode($line->basis_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $key = $snapshot['allocation_key'];
            $facts[$key] ??= ['quantity' => '0', 'amount_without_vat' => '0', 'amount_with_vat' => '0'];
            $facts[$key]['quantity'] = FinanceDecimal::add($facts[$key]['quantity'], (string) $line->quantity);
            $facts[$key]['amount_with_vat'] = FinanceDecimal::add($facts[$key]['amount_with_vat'], (string) $line->amount);
            $basePrice = $snapshot['base_unit_price'] ?? null;
            $facts[$key]['amount_without_vat'] = $facts[$key]['amount_without_vat'] === null || $basePrice === null
                ? null : FinanceDecimal::add($facts[$key]['amount_without_vat'], FinanceDecimal::multiply((string) $line->quantity, (string) $basePrice, 8));
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
