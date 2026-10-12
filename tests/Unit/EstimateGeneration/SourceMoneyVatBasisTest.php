<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis;
use PHPUnit\Framework\TestCase;

final class SourceMoneyVatBasisTest extends TestCase
{
    public function test_accepted_tiny_inclusive_line_keeps_zero_vat_and_mixed_lines_sum_exactly(): void
    {
        $service = new SourceMoneyVatBasis;
        $money = $this->tinyMoney();
        $record = $service->seal($this->identity(), $money);
        self::assertSame($money, $service->accepted($record, [...$this->identity(), 'net_amount' => '0.03']));
        self::assertSame(['net_amount' => '200.03', 'vat_amount' => '20.00', 'gross_amount' => '220.03'],
            $service->totals([$money, ['net_amount' => '100.00', 'vat_amount' => '20.00', 'gross_amount' => '120.00', 'quoted_amount' => '100.00', 'vat_mode' => 'excluded', 'vat_rate' => '20'],
                ['net_amount' => '100.00', 'vat_amount' => '0.00', 'gross_amount' => '100.00', 'quoted_amount' => '100.00', 'vat_mode' => 'not_applicable', 'vat_rate' => '0']]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedBasis')]
    public function test_a_changed_quantity_unit_scope_or_price_cannot_reuse_the_old_source_vat(string $field, mixed $value): void
    {
        $service = new SourceMoneyVatBasis;
        $record = $service->seal($this->identity(), $this->tinyMoney());
        $this->expectException(\DomainException::class);
        $service->accepted($record, [...$this->identity(), 'net_amount' => '0.03', $field => $value]);
    }

    public static function changedBasis(): array
    {
        return [['organization_id', 4], ['quantity', '2'], ['measurement_unit_id', 10], ['stored_unit_price', '0.04'], ['net_amount', '0.04']];
    }

    public function test_a_tampered_snapshot_or_unreconciled_money_is_rejected(): void
    {
        $service = new SourceMoneyVatBasis;
        $record = $service->seal($this->identity(), $this->tinyMoney());
        $record['money']['vat_amount'] = '0.01';
        $this->expectException(\DomainException::class);
        $service->accepted($record, [...$this->identity(), 'net_amount' => '0.03']);
    }

    private function identity(): array
    {
        return ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'work_item_key' => 'floor', 'measurement_unit_id' => 9,
            'quantity' => '1', 'stored_unit_price' => '0.0300', 'currency' => 'RUB', 'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64)];
    }

    public function test_reconciled_amounts_still_require_real_tax_terms_and_correct_source_rounding(): void
    {
        $this->expectException(\DomainException::class);
        (new SourceMoneyVatBasis)->seal($this->identity(), [...$this->tinyMoney(), 'vat_amount' => '0.01', 'gross_amount' => '0.04']);
    }

    private function tinyMoney(): array
    {
        return ['net_amount' => '0.03', 'vat_amount' => '0.00', 'gross_amount' => '0.03', 'quoted_amount' => '0.03', 'vat_mode' => 'included', 'vat_rate' => '20'];
    }
}
