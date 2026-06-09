<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDrillDownKey;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastAdjustmentRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastDrillDownRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastReportRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastVersionListRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastVersionRequest;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\TestCase;

final class WipForecastApiContractTest extends TestCase
{
    public function test_report_request_accepts_wip_filters_and_grouping_contract(): void
    {
        $rules = (new WipForecastReportRequest())->rules();

        $this->assertArrayHasKey('period_start', $rules);
        $this->assertArrayHasKey('period_end', $rules);
        $this->assertArrayHasKey('as_of_date', $rules);
        $this->assertArrayHasKey('forecast_version_uuid', $rules);
        $this->assertArrayHasKey('budget_version_uuid', $rules);
        $this->assertArrayHasKey('scenario_uuid', $rules);
        $this->assertArrayHasKey('project_id', $rules);
        $this->assertArrayHasKey('stage_id', $rules);
        $this->assertArrayHasKey('contract_id', $rules);
        $this->assertArrayHasKey('estimate_item_id', $rules);
        $this->assertArrayHasKey('currency', $rules);
        $this->assertNotEmpty(array_filter(
            $rules['group_by.*'],
            static fn (mixed $rule): bool => $rule instanceof In,
        ));
        $this->assertContains(WipForecastReportFilters::GROUP_PROJECT, WipForecastReportFilters::ALLOWED_GROUP_BY);
        $this->assertContains(WipForecastReportFilters::GROUP_STAGE, WipForecastReportFilters::ALLOWED_GROUP_BY);
        $this->assertContains(WipForecastReportFilters::GROUP_CONTRACT, WipForecastReportFilters::ALLOWED_GROUP_BY);
        $this->assertContains(WipForecastReportFilters::GROUP_ESTIMATE_ITEM, WipForecastReportFilters::ALLOWED_GROUP_BY);
        $this->assertContains(WipForecastReportFilters::GROUP_PERIOD, WipForecastReportFilters::ALLOWED_GROUP_BY);
    }

    public function test_drill_down_request_requires_key_and_paginates_safely(): void
    {
        $rules = (new WipForecastDrillDownRequest())->rules();

        $this->assertSame(['required', 'string', 'max:2000'], $rules['drill_down_key']);
        $this->assertSame(['sometimes', 'nullable', 'integer', 'min:1'], $rules['page']);
        $this->assertSame(['sometimes', 'nullable', 'integer', 'min:1', 'max:500'], $rules['per_page']);
    }

    public function test_drill_down_key_supports_dimension_checks(): void
    {
        $key = WipForecastDrillDownKey::decode(
            WipForecastDrillDownKey::encode(
                [WipForecastReportFilters::GROUP_PROJECT],
                [
                    'project' => 42,
                    'currency' => 'RUB',
                ],
            ),
        );

        $this->assertTrue($key->hasDimension('project'));
        $this->assertTrue($key->hasDimension('currency'));
        $this->assertFalse($key->hasDimension('stage'));
    }

    public function test_version_list_request_keeps_period_optional(): void
    {
        $rules = (new WipForecastVersionListRequest())->rules();

        $this->assertArrayHasKey('period_start', $rules);
        $this->assertArrayHasKey('period_end', $rules);
        $this->assertNotContains('required', $rules['period_start']);
        $this->assertNotContains('required', $rules['period_end']);
        $this->assertArrayHasKey('budget_version_uuid', $rules);
        $this->assertArrayHasKey('scenario_uuid', $rules);
    }

    public function test_version_and_adjustment_requests_require_business_reason_for_adjustments(): void
    {
        $versionRules = (new WipForecastVersionRequest())->rules();
        $adjustmentRules = (new WipForecastAdjustmentRequest())->rules();

        $this->assertContains('required', $versionRules['name']);
        $this->assertSame(['required', 'numeric'], $adjustmentRules['amount']);
        $this->assertSame(['required', 'string', 'max:2000'], $adjustmentRules['reason']);
        $this->assertNotEmpty(array_filter(
            $adjustmentRules['formula_component'],
            static fn (mixed $rule): bool => $rule instanceof In,
        ));
    }
}
