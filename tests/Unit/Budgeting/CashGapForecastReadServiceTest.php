<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class CashGapForecastReadServiceTest extends TestCase
{
    public function test_read_service_normalizes_forecast_defaults_for_non_http_callers(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3)
            . '/app/BusinessModules/Features/Budgeting/Services/CashGapForecastReadService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('$request = $this->normalizeBuildRequest($request);', $source);
        $this->assertStringContainsString("'granularity' => 'day'", $source);
        $this->assertStringContainsString("'scenario' => CashGapForecastContext::SCENARIO_BASE", $source);
        $this->assertStringContainsString("'scenario_adjustments' => []", $source);
    }
}
