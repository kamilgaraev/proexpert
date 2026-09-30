<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead as Finance;
use PHPUnit\Framework\TestCase;

final class AssistantLegacyFinancialReadTest extends TestCase
{
    public function test_decimal_money_above_double_precision_is_exact_and_rounds_half_up(): void
    {
        self::assertSame('9007199254740993.22', Finance::money('9007199254740993.215'));
        self::assertSame('9007199254740993.23', Finance::sum(['9007199254740993.21', '0.01', '0.01']));
        self::assertSame('0.01', Finance::subtract('9007199254740993.22', '9007199254740993.21'));
        self::assertSame('33.33', Finance::percentage('1.00', '3.00'));
    }

    public function test_unavailable_values_and_missing_basis_never_become_zero(): void
    {
        self::assertNull(Finance::money(null));
        self::assertNull(Finance::sum(['0.01', null]));
        self::assertNull(Finance::subtract('1.00', null));
        self::assertNull(Finance::percentage('1.00', '0.00'));
        self::assertNull(Finance::percentage(null, '1.00'));
        self::assertSame('0.00', Finance::sum([]));
    }
}
