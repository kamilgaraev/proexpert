<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportPolling;
use PHPUnit\Framework\TestCase;

final class AssistantPublishedReportPollingTest extends TestCase
{
    public function test_pending_checks_back_off_to_one_minute(): void
    {
        foreach ([-1 => 5, 0 => 5, 29 => 5, 30 => 15, 119 => 15, 120 => 30, 599 => 30, 600 => 60, 1800 => 60] as $age => $expected) {
            self::assertSame($expected, AssistantPublishedReportPolling::seconds($age));
        }
    }

    public function test_thirty_minute_wait_needs_fewer_than_fifty_checks(): void
    {
        $age = 0;
        $checks = 0;
        while ($age < 1800) {
            $age += AssistantPublishedReportPolling::seconds($age);
            $checks++;
        }
        self::assertLessThan(50, $checks);
        self::assertGreaterThan(0, $checks);
    }
}
