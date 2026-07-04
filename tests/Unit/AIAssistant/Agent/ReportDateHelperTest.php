<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReportDateHelper;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class ReportDateHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_extracts_explicit_date_arguments(): void
    {
        $helper = new class
        {
            use ReportDateHelper;

            /**
             * @param  array<string, mixed>  $arguments
             * @return array{date_from: string|null, date_to: string|null}
             */
            public function extract(array $arguments): array
            {
                return $this->extractPeriodFromArguments($arguments, 'за этот месяц');
            }
        };

        $dates = $helper->extract([
            'date_from' => '2026-04-20',
            'date_to' => '2026-05-04',
            'period' => 'за этот месяц',
        ]);

        $this->assertSame('2026-04-20 00:00:00', $dates['date_from']);
        $this->assertSame('2026-05-04 23:59:59', $dates['date_to']);
    }

    public function test_reversed_explicit_dates_fall_back_to_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'Europe/Moscow'));

        $helper = new class
        {
            use ReportDateHelper;

            /**
             * @param  array<string, mixed>  $arguments
             * @return array{date_from: string|null, date_to: string|null}
             */
            public function extract(array $arguments): array
            {
                return $this->extractPeriodFromArguments($arguments, 'за этот месяц');
            }
        };

        $dates = $helper->extract([
            'date_from' => '2026-05-04',
            'date_to' => '2026-04-20',
            'period' => 'за этот месяц',
        ]);

        $this->assertSame('2026-05-01 00:00:00', $dates['date_from']);
        $this->assertSame('2026-05-31 23:59:59', $dates['date_to']);
    }

    public function test_extracts_open_project_start_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'Europe/Moscow'));

        $helper = new class
        {
            use ReportDateHelper;

            /**
             * @param  array<string, mixed>  $arguments
             * @return array{date_from: string|null, date_to: string|null}
             */
            public function extract(array $arguments): array
            {
                return $this->extractPeriodFromArguments($arguments, 'за этот месяц');
            }
        };

        $dates = $helper->extract([
            'period' => 'с начала проекта по сегодняшний день',
        ]);

        $this->assertNull($dates['date_from']);
        $this->assertSame('2026-05-04 23:59:59', $dates['date_to']);
    }

    public function test_accepts_explicit_open_upper_bound(): void
    {
        $helper = new class
        {
            use ReportDateHelper;

            /**
             * @param  array<string, mixed>  $arguments
             * @return array{date_from: string|null, date_to: string|null}
             */
            public function extract(array $arguments): array
            {
                return $this->extractPeriodFromArguments($arguments, 'за этот месяц');
            }
        };

        $dates = $helper->extract([
            'date_from' => null,
            'date_to' => '2026-05-04',
            'period' => 'с начала проекта по сегодняшний день',
        ]);

        $this->assertNull($dates['date_from']);
        $this->assertSame('2026-05-04 23:59:59', $dates['date_to']);
    }
}
