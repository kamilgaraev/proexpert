<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\CanonicalDraftMoneyTotals;
use PHPUnit\Framework\TestCase;

final class CanonicalDraftMoneyTotalsTest extends TestCase
{
    public function test_large_resource_rounded_totals_and_fixed_contingency_keep_every_kopeck(): void
    {
        $draft = ['generation_contract' => 'most_ordinary_estimate:v1', 'contingency_amount' => '0.01',
            'local_estimates' => [['sections' => [['work_items' => [['total_cost' => '999999999999.90'], ['total_cost' => '0.10']]]]]]];
        $result = (new CanonicalDraftMoneyTotals)->project($draft);
        self::assertSame('1000000000000.00', $result['totals']['base_total_cost']);
        self::assertSame('1000000000000.01', $result['totals']['total_cost']);
        self::assertSame('0.01', $result['contingency_amount']);
        self::assertSame('1000000000000.00', $result['local_estimates'][0]['sections'][0]['section_totals']['total_cost']);
    }

    public function test_unknown_money_cannot_be_turned_into_zero_by_summary_projection(): void
    {
        $this->expectException(\DomainException::class);
        (new CanonicalDraftMoneyTotals)->project(['generation_contract' => 'most_ordinary_estimate:v1',
            'local_estimates' => [['sections' => [['work_items' => [['total_cost' => null]]]]]]]);
    }
}
