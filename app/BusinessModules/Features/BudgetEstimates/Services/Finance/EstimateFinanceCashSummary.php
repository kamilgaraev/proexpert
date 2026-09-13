<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceCashSummary
{
    public function calculate(array $sources): array
    {
        $totals = [];
        $contracts = [];
        $seen = [];
        foreach ($sources as $source) {
            if (isset($seen[$source['transaction_id']])) {
                continue;
            }
            $seen[$source['transaction_id']] = true;
            $currency = $source['currency'];
            $contractId = $source['contract_id'];
            $totals[$currency] ??= $this->emptyTotal();
            $contracts[$contractId][$currency] ??= $this->emptyTotal();
            $this->add($totals[$currency], $source);
            $this->add($contracts[$contractId][$currency], $source);
        }
        foreach ($totals as &$total) {
            $total = $this->finish($total);
        }
        unset($total);
        foreach ($contracts as &$currencies) {
            foreach ($currencies as &$total) {
                $total = $this->finish($total);
            }
            unset($total);
        }
        unset($currencies);

        return ['scope' => 'linked_contracts', 'totals' => $totals, 'contracts' => $contracts];
    }

    private function emptyTotal(): array
    {
        return ['known_receipts' => '0.00', 'known_payments' => '0.00', 'known_customer_refunds' => '0.00',
            'known_contractor_refunds' => '0.00', 'unclassified_count' => 0, 'sources_count' => 0];
    }

    private function add(array &$total, array $source): void
    {
        $total['sources_count']++;
        if ($source['amount'] === null || $source['currency'] === '' || $source['direction_requires_review']
            || ! in_array($source['side'], ['revenue', 'cost'], true)) {
            $total['unclassified_count']++;

            return;
        }
        $amount = FinanceDecimal::value($source['amount']);
        $negative = FinanceDecimal::compare($amount, '0') < 0;
        $absolute = $negative ? FinanceDecimal::subtract('0', $amount) : $amount;
        $receipt = ($source['side'] === 'revenue') !== $negative;
        $field = $receipt ? 'known_receipts' : 'known_payments';
        $total[$field] = FinanceDecimal::add($total[$field], $absolute);
        if ($negative) {
            $refund = $source['side'] === 'revenue' ? 'known_customer_refunds' : 'known_contractor_refunds';
            $total[$refund] = FinanceDecimal::add($total[$refund], $absolute);
        }
    }

    private function finish(array $total): array
    {
        foreach (['known_receipts', 'known_payments', 'known_customer_refunds', 'known_contractor_refunds'] as $field) {
            $total[$field] = FinanceDecimal::value($total[$field]);
        }
        $known = $total['unclassified_count'] === 0;

        return $total + ['receipts' => $known ? $total['known_receipts'] : null, 'payments' => $known ? $total['known_payments'] : null,
            'customer_refunds' => $known ? $total['known_customer_refunds'] : null, 'contractor_refunds' => $known ? $total['known_contractor_refunds'] : null,
            'difference' => $known ? FinanceDecimal::subtract($total['known_receipts'], $total['known_payments']) : null];
    }
}
