<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Widgets\SaaSIncomeStatsWidget;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
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

    public function test_it_does_not_crash_when_payment_transactions_table_is_missing(): void
    {
        Schema::dropIfExists('payment_transactions');
        RefreshDatabaseState::$migrated = false;

        $method = new ReflectionMethod(SaaSIncomeStatsWidget::class, 'getStats');
        $method->setAccessible(true);

        $stats = $method->invoke(app(SaaSIncomeStatsWidget::class));

        $this->assertCount(2, $stats);
        $this->assertStringContainsString('0', (string) $stats[0]->getValue());
        $this->assertStringContainsString('0', (string) $stats[1]->getValue());
    }
}
