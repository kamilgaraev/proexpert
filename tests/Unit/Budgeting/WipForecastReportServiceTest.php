<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\Services\WipForecastCalculator;
use App\BusinessModules\Features\Budgeting\Services\WipForecastReportService;
use App\Domain\Authorization\Services\AuthorizationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WipForecastReportServiceTest extends TestCase
{
    public function test_stored_forecast_lines_filter_month_period_as_string_key(): void
    {
        $service = new WipForecastReportService(
            new WipForecastCalculator(),
            $this->createMock(AuthorizationService::class),
        );

        $method = new ReflectionMethod(WipForecastReportService::class, 'storedLinePeriodBounds');
        $bounds = $method->invoke($service, $this->filters());

        $this->assertSame(['2026-05', '2026-06'], $bounds);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $bounds[0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $bounds[1]);
    }

    private function filters(): WipForecastReportFilters
    {
        return new WipForecastReportFilters(
            organizationId: 72,
            periodStart: '2026-05-31',
            periodEnd: '2026-06-29',
            asOfDate: '2026-06-29',
            forecastVersionId: null,
            forecastVersionUuid: '4dcfe43c-d342-4228-9027-e64ce2befb6f',
            budgetVersionId: null,
            budgetVersionUuid: null,
            scenarioId: null,
            scenarioUuid: null,
            projectId: 88,
            stageId: null,
            contractId: null,
            estimateItemId: null,
            currency: null,
            groupBy: [
                WipForecastReportFilters::GROUP_PERIOD,
                WipForecastReportFilters::GROUP_PROJECT,
                WipForecastReportFilters::GROUP_CONTRACT,
                WipForecastReportFilters::GROUP_CURRENCY,
            ],
        );
    }
}
