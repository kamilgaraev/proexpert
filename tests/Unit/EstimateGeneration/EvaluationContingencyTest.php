<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationContingency;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator;
use App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis;
use PHPUnit\Framework\TestCase;

final class EvaluationContingencyTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('moneyCases')]
    public function test_reserve_is_a_separate_component_and_uses_its_own_vat(string $mode, string $amount, string $net, string $vat, string $gross): void
    {
        $component = (new EvaluationContingency)->component($this->selection($mode, $amount));
        self::assertSame([$net, $vat, $gross], [$component['money']['net_amount'], $component['money']['vat_amount'], $component['money']['gross_amount']]);
        self::assertSame($net, (new SourceMoneyVatBasis)->totals([$component['money']])['net_amount']);
        $position = ['key' => 'equipment', 'name' => 'Оборудование', 'section' => 'equipment', 'quantity' => '1', 'unit' => 'count',
            'price_snapshot' => ['unit_price' => '10', 'unit' => 'count', 'verified' => true, 'source_type' => 'catalog',
                'source_reference' => 'catalog:1', 'currency' => 'RUB', 'as_of_date' => '2026-10-12', 'vat_mode' => 'not_applicable']];
        $result = (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [$position], 'assumptions' => [], 'contingency' => $component]], ['equipment'], [], true);
        self::assertCount(1, $result['scenarios'][0]['positions']);
        self::assertSame($component, $result['scenarios'][0]['contingency']);
        self::assertSame((string) \Brick\Math\BigDecimal::of('10')->plus($gross)->toScale(2), $result['scenarios'][0]['total_by_currency']['RUB']);
        self::assertTrue($result['can_confirm_for_apply']);
    }

    public static function moneyCases(): array
    {
        return [['included', '120', '100.00', '20.00', '120.00'], ['excluded', '100', '100.00', '20.00', '120.00'],
            ['not_applicable', '100', '100.00', '0.00', '100.00'], ['included', '0.03', '0.03', '0.00', '0.03'],
            ['not_applicable', '0', '0.00', '0.00', '0.00']];
    }

    public function test_a_reserve_cannot_make_unknown_work_quantities_complete(): void
    {
        $component = (new EvaluationContingency)->component($this->selection('excluded', '100'));
        $result = (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [['key' => 'unknown', 'section' => 'equipment',
            'quantity' => null, 'unit' => 'count', 'price_snapshot' => null]], 'assumptions' => [], 'contingency' => $component]], ['equipment'], [], true);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertNull($result['scenarios'][0]['total_by_currency']);
        self::assertSame(['RUB' => '120.00'], $result['scenarios'][0]['gross_subtotals']);
    }

    private function selection(string $mode, string $amount): array
    {
        return ['kind' => 'fixed', 'currency' => 'RUB', 'quoted_amount' => $amount, 'vat_mode' => $mode, 'vat_rate' => '20',
            'basis' => 'Согласованный резерв выбранного состава.', 'fact_id' => 'fact:'.str_repeat('a', 64), 'decision_id' => 'decision:'.str_repeat('b', 64)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTerms')]
    public function test_invalid_or_unconfirmed_reserve_is_rejected(array $changed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationContingency)->component([...$this->selection('included', '120'), ...$changed]);
    }

    public static function invalidTerms(): array
    {
        return [[['quoted_amount' => '-1']], [['quoted_amount' => '120.001']], [['currency' => 'USD']], [['kind' => 'percent']],
            [['basis' => ' ']], [['vat_rate' => '101']], [['fact_id' => 'fact:unconfirmed']]];
    }
}
