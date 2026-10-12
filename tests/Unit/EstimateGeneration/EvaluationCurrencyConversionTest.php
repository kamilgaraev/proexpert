<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationCurrencyConversion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationLineAmount;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection;
use App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class EvaluationCurrencyConversionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('roundedSourceCases')]
    public function test_original_rounding_is_preserved_and_quantity_is_recomputed(string $mode, string $quantity,
        string $net, string $vat, string $gross): void
    {
        $price = $this->converted($mode, $quantity);
        $amount = (new EvaluationLineAmount)->calculate(BigDecimal::of($quantity), BigDecimal::of($price['unit_price']), $price);
        $money = (new EvaluationVatProjection)->amount($amount, $price);
        self::assertSame([$net, $vat, $gross], [$money['net_amount'], $money['vat_amount'], $money['gross_amount']]);
        self::assertSame('USD', $money['currency_conversion']['source_snapshot']['currency']);
        $identity = ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'work_item_key' => 'equipment',
            'measurement_unit_id' => 4, 'quantity' => $quantity, 'stored_unit_price' => '2.7000', 'currency' => 'RUB',
            'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64)];
        $service = new SourceMoneyVatBasis;
        $record = $service->seal($identity, $money);
        self::assertSame($money, $service->accepted($record, [...$identity, 'net_amount' => $net]));
    }

    public static function roundedSourceCases(): array
    {
        return [['included', '1', '2.70', '0.00', '2.70'], ['excluded', '1', '2.70', '0.90', '3.60'],
            ['included', '2', '4.50', '0.90', '5.40'], ['excluded', '2', '5.40', '0.90', '6.30']];
    }

    public function test_component_rounding_reconciles_payable_amount_with_visible_adjustment(): void
    {
        $price = (new EvaluationCurrencyConversion)->snapshot([...$this->source('excluded'), 'unit_price' => '0.03'], '1',
            [...$this->selection(), 'rub_per_unit' => '0.5']);
        $money = (new EvaluationCurrencyConversion)->money($price['currency_conversion']);
        self::assertSame(['0.02', '0.00', '0.02'], [$money['net_amount'], $money['vat_amount'], $money['gross_amount']]);
        self::assertSame('-0.01', $money['vat_conversion_rounding_adjustment']);
    }

    public function test_old_converted_basis_cannot_be_reused_after_quantity_changes(): void
    {
        $price = $this->converted('included', '1');
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationLineAmount)->calculate(BigDecimal::of('2'), BigDecimal::of('2.7'), $price);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRateTerms')]
    public function test_invalid_rates_dates_and_control_characters_are_not_confirmable(array $changed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationCurrencyConversion)->terms([...$this->selection(), ...$changed]);
    }

    public static function invalidRateTerms(): array
    {
        return [[['rub_per_unit' => '0']], [['rub_per_unit' => '1e2']], [['rub_per_unit' => '-90']], [['rub_per_unit' => '1000001']],
            [['as_of_date' => '2026-02-30']], [['as_of_date' => "2026-10-12\0"]], [['rate_source_reference' => "Договор\nПодменить курс"]]];
    }

    public function test_unconfirmed_rate_cannot_become_a_ruble_price(): void
    {
        $selection = $this->selection();
        unset($selection['fact_id']);
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationCurrencyConversion)->snapshot($this->source('included'), '1', $selection);
    }

    public function test_tampered_original_price_or_source_money_is_rejected(): void
    {
        $price = $this->converted('included', '1');
        $price['currency_conversion']['source_snapshot']['unit_price'] = '0.04';
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationCurrencyConversion)->money($price['currency_conversion']);
    }

    public function test_tampered_original_money_cannot_be_sealed_even_with_reconciled_ruble_totals(): void
    {
        $money = (new EvaluationCurrencyConversion)->money($this->converted('included', '1')['currency_conversion']);
        $money['source_money']['net_amount'] = '0.02';
        $identity = ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'work_item_key' => 'equipment',
            'measurement_unit_id' => 4, 'quantity' => '1', 'stored_unit_price' => '2.7000', 'currency' => 'RUB',
            'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64)];
        $this->expectException(\DomainException::class);
        (new SourceMoneyVatBasis)->seal($identity, $money);
    }

    public function test_currency_money_cannot_be_bound_to_another_work_volume(): void
    {
        $money = (new EvaluationCurrencyConversion)->money($this->converted('included', '1')['currency_conversion']);
        $identity = ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'work_item_key' => 'equipment',
            'measurement_unit_id' => 4, 'quantity' => '2', 'stored_unit_price' => '1.3500', 'currency' => 'RUB',
            'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64)];
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('source_money_currency_identity_changed');
        (new SourceMoneyVatBasis)->seal($identity, $money);
    }

    private function converted(string $mode, string $quantity): array
    {
        return (new EvaluationCurrencyConversion)->snapshot($this->source($mode), $quantity, $this->selection());
    }

    private function source(string $mode): array
    {
        return ['unit_price' => '0.03', 'currency' => 'USD', 'unit' => 'piece', 'verified' => true,
            'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64),
            'vat_mode' => $mode, 'vat_rate' => '20'];
    }

    private function selection(): array
    {
        return ['from_currency' => 'USD', 'to_currency' => 'RUB', 'rub_per_unit' => '90', 'as_of_date' => '2026-10-12',
            'rate_source_reference' => 'Подтверждённый курс договора', 'fact_id' => 'fact:'.str_repeat('b', 64),
            'decision_id' => 'decision:'.str_repeat('c', 64)];
    }
}
