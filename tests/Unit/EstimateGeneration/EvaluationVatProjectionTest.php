<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection;
use PHPUnit\Framework\TestCase;

final class EvaluationVatProjectionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('amountCases')]
    public function test_source_vat_is_projected_once_with_exact_net_plus_vat_equal_gross(string $amount, string $mode, ?string $rate,
        string $net, string $vat, string $gross): void
    {
        $result = (new EvaluationVatProjection)->amount($amount, ['vat_mode' => $mode, 'vat_rate' => $rate]);
        self::assertSame($net, $result['net_amount']);
        self::assertSame($vat, $result['vat_amount']);
        self::assertSame($gross, $result['gross_amount']);
        self::assertSame($gross, (string) \Brick\Math\BigDecimal::of($net)->plus($vat));
    }

    public static function amountCases(): array
    {
        return [
            ['1200.00', 'included', '20', '1000.00', '200.00', '1200.00'],
            ['1000.00', 'excluded', '20', '1000.00', '200.00', '1200.00'],
            ['1000.00', 'not_applicable', null, '1000.00', '0.00', '1000.00'],
            ['0.03', 'included', '20', '0.03', '0.00', '0.03'],
            ['1.01', 'included', '20', '0.84', '0.17', '1.01'],
            ['1.01', 'excluded', '20', '1.01', '0.20', '1.21'],
            ['1.01', 'included', '0', '1.01', '0.00', '1.01'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTerms')]
    public function test_unknown_or_invalid_source_tax_terms_do_not_default_to_zero(array $price): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EvaluationVatProjection)->amount('10.00', $price);
    }

    public static function invalidTerms(): array
    {
        return [[[]], [['vat_mode' => 'excluded']], [['vat_mode' => 'included', 'vat_rate' => '-20']],
            [['vat_mode' => 'included', 'vat_rate' => '101']], [['vat_mode' => 'included', 'vat_rate' => 20.0]],
            [['vat_mode' => 'invented', 'vat_rate' => '20']]];
    }
}
