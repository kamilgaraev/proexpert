<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportPeriodFilter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class AssistantOperationalReportPeriodFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_without_explicit_period_uses_all_available_period(): void
    {
        $period = (new AssistantOperationalReportPeriodFilter)->resolve([]);

        $this->assertSame('весь доступный период', $period['period']);
        $this->assertNull($period['date_from']);
        $this->assertNull($period['date_to']);
        $this->assertFalse($period['is_explicit']);
    }

    public function test_explicit_period_is_resolved_to_dates(): void
    {
        $period = (new AssistantOperationalReportPeriodFilter)->resolve([
            'period' => 'за этот месяц',
        ]);

        $this->assertSame('за этот месяц', $period['period']);
        $this->assertSame('2026-07-01 00:00:00', $period['date_from']);
        $this->assertSame('2026-07-31 23:59:59', $period['date_to']);
        $this->assertTrue($period['is_explicit']);
    }

    public function test_explicit_dates_are_resolved_without_text_period(): void
    {
        $period = (new AssistantOperationalReportPeriodFilter)->resolve([
            'date_from' => '2026-01-10',
            'date_to' => '2026-02-15',
        ]);

        $this->assertSame('выбранный период', $period['period']);
        $this->assertSame('2026-01-10 00:00:00', $period['date_from']);
        $this->assertSame('2026-02-15 23:59:59', $period['date_to']);
        $this->assertTrue($period['is_explicit']);
    }
}
