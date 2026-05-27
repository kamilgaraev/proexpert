<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Widgets\SaaSIncomeStatsWidget;
use ReflectionMethod;
use Tests\TestCase;

class SaaSIncomeStatsWidgetTest extends TestCase
{
    public function test_it_formats_postgresql_decimal_aggregate_strings(): void
    {
        $method = new ReflectionMethod(SaaSIncomeStatsWidget::class, 'normalizeCurrencyAmount');
        $method->setAccessible(true);

        $this->assertSame(1234.56, $method->invoke(null, '1234.56'));
        $this->assertSame(0.0, $method->invoke(null, null));
    }
}
