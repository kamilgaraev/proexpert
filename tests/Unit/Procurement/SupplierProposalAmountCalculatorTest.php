<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\SupplierProposalAmountCalculator;
use PHPUnit\Framework\TestCase;

final class SupplierProposalAmountCalculatorTest extends TestCase
{
    public function test_included_vat_uses_server_line_totals_and_single_rounding_policy(): void
    {
        $amounts = SupplierProposalAmountCalculator::calculate([
            'subtotal_amount' => 1,
            'delivery_amount' => 9.99,
            'vat_amount' => 999,
            'total_amount' => 9999,
            'vat_mode' => 'included',
            'vat_rate' => 20,
            'items' => [[
                'quantity' => 3,
                'unit_price' => 33.335,
                'total_amount' => 1,
            ]],
        ]);

        self::assertSame(100.01, $amounts['subtotal_amount']);
        self::assertSame(9.99, $amounts['delivery_amount']);
        self::assertSame(18.33, $amounts['vat_amount']);
        self::assertSame(110.0, $amounts['total_amount']);
        self::assertSame(100.01, SupplierProposalAmountCalculator::lineTotal(3, 33.335));
    }

    public function test_excluded_and_not_applicable_vat_are_canonical(): void
    {
        self::assertSame([
            'subtotal_amount' => 20.01,
            'delivery_amount' => 4.99,
            'vat_amount' => 5.0,
            'total_amount' => 30.0,
        ], SupplierProposalAmountCalculator::calculate([
            'subtotal_amount' => 999,
            'delivery_amount' => 4.99,
            'total_amount' => 1,
            'vat_mode' => 'excluded',
            'vat_rate' => 20,
            'items' => [['quantity' => 2, 'unit_price' => 10.005]],
        ]));

        self::assertSame(25.0, SupplierProposalAmountCalculator::calculate([
            'subtotal_amount' => 20.01,
            'delivery_amount' => 4.99,
            'total_amount' => 999,
            'vat_mode' => 'not_applicable',
            'vat_rate' => 20,
        ])['total_amount']);
    }
}
