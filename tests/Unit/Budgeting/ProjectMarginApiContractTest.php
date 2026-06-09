<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectMarginDrillDownRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectMarginReportRequest;
use PHPUnit\Framework\TestCase;

final class ProjectMarginApiContractTest extends TestCase
{
    public function test_report_request_accepts_margin_filters_and_grouping_contract(): void
    {
        $rules = (new ProjectMarginReportRequest())->rules();

        $this->assertArrayHasKey('period_start', $rules);
        $this->assertArrayHasKey('period_end', $rules);
        $this->assertArrayHasKey('project_id', $rules);
        $this->assertArrayHasKey('contract_id', $rules);
        $this->assertArrayHasKey('budget_article_id', $rules);
        $this->assertArrayHasKey('responsibility_center_id', $rules);
        $this->assertArrayHasKey('counterparty_id', $rules);
        $this->assertArrayHasKey('currency', $rules);
        $this->assertContains(ProjectMarginReportFilters::GROUP_CONTRACT, ProjectMarginReportFilters::ALLOWED_GROUP_BY);
        $this->assertContains(ProjectMarginReportFilters::GROUP_COUNTERPARTY, ProjectMarginReportFilters::ALLOWED_GROUP_BY);
    }

    public function test_drill_down_request_requires_key_and_paginates_safely(): void
    {
        $rules = (new ProjectMarginDrillDownRequest())->rules();

        $this->assertSame(['required', 'string', 'max:2000'], $rules['drill_down_key']);
        $this->assertSame(['sometimes', 'nullable', 'integer', 'min:1'], $rules['page']);
        $this->assertSame(['sometimes', 'nullable', 'integer', 'min:1', 'max:500'], $rules['per_page']);
    }
}
