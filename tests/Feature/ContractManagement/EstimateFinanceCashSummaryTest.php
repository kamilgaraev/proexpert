<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceCashSummary;
use Tests\TestCase;

final class EstimateFinanceCashSummaryTest extends TestCase
{
    public function test_receipts_and_payments_include_refunds_in_the_actual_movement_direction(): void
    {
        $receipt = $this->source(1, 11, 'revenue', '400.01');
        $result = app(EstimateFinanceCashSummary::class)->calculate([$receipt, $this->source(2, 11, 'revenue', '-100.01'),
            $this->source(3, 12, 'cost', '200.02'), $this->source(4, 12, 'cost', '-50.01'), $receipt,
            $this->source(5, 11, 'revenue', '10.50', 'USD')]);
        self::assertSame('450.02', $result['totals']['RUB']['receipts']);
        self::assertSame('300.03', $result['totals']['RUB']['payments']);
        self::assertSame('149.99', $result['totals']['RUB']['difference']);
        self::assertSame('100.01', $result['totals']['RUB']['customer_refunds']);
        self::assertSame('50.01', $result['totals']['RUB']['contractor_refunds']);
        self::assertSame(4, $result['totals']['RUB']['sources_count']);
        self::assertSame('10.50', $result['totals']['USD']['receipts']);
        self::assertSame('300.00', $result['contracts'][11]['RUB']['difference']);
        self::assertSame('-150.01', $result['contracts'][12]['RUB']['difference']);
        self::assertSame('linked_contracts', $result['scope']);
    }

    public function test_unknown_direction_or_amount_does_not_turn_into_zero_or_complete_totals(): void
    {
        $result = app(EstimateFinanceCashSummary::class)->calculate([$this->source(1, 11, 'revenue', '100'),
            $this->source(2, 11, 'unknown', '50'), $this->source(3, 12, 'cost', null)]);
        $total = $result['totals']['RUB'];
        self::assertSame('100.00', $total['known_receipts']);
        self::assertNull($total['receipts']);
        self::assertNull($total['payments']);
        self::assertNull($total['difference']);
        self::assertNull($total['customer_refunds']);
        self::assertSame(2, $total['unclassified_count']);
        self::assertSame([], app(EstimateFinanceCashSummary::class)->calculate([])['totals']);
    }

    private function source(int $id, int $contract, string $side, ?string $amount, string $currency = 'RUB'): array
    {
        return ['transaction_id' => $id, 'contract_id' => $contract, 'side' => $side, 'amount' => $amount, 'currency' => $currency,
            'direction_requires_review' => $side === 'unknown'];
    }
}
