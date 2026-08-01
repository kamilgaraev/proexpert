<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardCanonicalizerTest extends TestCase
{
    #[DataProvider('decimalProvider')]
    public function test_decimal_values_are_canonical_without_php_float(mixed $value, string $expected): void
    {
        self::assertSame($expected, ProcurementAwardCanonicalizer::decimal($value));
    }

    public static function decimalProvider(): array
    {
        return [
            'integer' => [12, '12'],
            'leading zeroes' => ['00012.3400', '12.34'],
            'negative zero' => ['-0.000', '0'],
            'fraction' => ['.5000', '0.5'],
        ];
    }

    public function test_float_is_rejected_before_it_can_change_commercial_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_award_float_forbidden');

        ProcurementAwardCanonicalizer::decimal(0.1);
    }

    public function test_hash_is_independent_of_associative_key_order_but_not_list_order(): void
    {
        $left = ProcurementAwardCanonicalizer::hash([
            'currency' => 'RUB',
            'amounts' => ['total' => '12.34', 'vat' => '2.34'],
            'lines' => [10, 20],
        ]);
        $right = ProcurementAwardCanonicalizer::hash([
            'lines' => [10, 20],
            'amounts' => ['vat' => '2.34', 'total' => '12.34'],
            'currency' => 'RUB',
        ]);
        $reorderedLines = ProcurementAwardCanonicalizer::hash([
            'currency' => 'RUB',
            'amounts' => ['total' => '12.34', 'vat' => '2.34'],
            'lines' => [20, 10],
        ]);

        self::assertSame($left, $right);
        self::assertNotSame($left, $reorderedLines);
    }

    public function test_sensitive_or_free_text_fields_are_rejected_recursively(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('procurement_award_sensitive_field_forbidden');

        ProcurementAwardCanonicalizer::assertSafe([
            'candidate' => [
                'supplier' => [
                    'email' => 'secret@example.test',
                ],
            ],
        ]);
    }
}
