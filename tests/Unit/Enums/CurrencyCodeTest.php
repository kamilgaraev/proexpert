<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CurrencyCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyCodeTest extends TestCase
{
    #[Test]
    public function it_exposes_the_supported_currency_codes_in_stable_order(): void
    {
        self::assertSame(['RUB', 'USD', 'EUR'], CurrencyCode::values());
        self::assertSame(
            ['RUB' => 'RUB', 'USD' => 'USD', 'EUR' => 'EUR'],
            CurrencyCode::options(),
        );
        self::assertSame(CurrencyCode::RUB, CurrencyCode::tryFrom('RUB'));
        self::assertSame(CurrencyCode::USD, CurrencyCode::tryFrom('USD'));
        self::assertSame(CurrencyCode::EUR, CurrencyCode::tryFrom('EUR'));
        self::assertNull(CurrencyCode::tryFrom('CNY'));
    }
}
