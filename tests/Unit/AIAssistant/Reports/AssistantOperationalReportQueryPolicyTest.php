<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportQueryPolicy;
use PHPUnit\Framework\TestCase;

final class AssistantOperationalReportQueryPolicyTest extends TestCase
{
    public function test_selected_project_summary_uses_project_id_and_ignores_created_at_period(): void
    {
        $policy = new AssistantOperationalReportQueryPolicy;
        $hasColumn = static fn (string $table, string $column): bool => $table === 'projects'
            && in_array($column, ['id', 'organization_id', 'created_at'], true);

        $this->assertSame('id', $policy->projectColumn('projects', $hasColumn));
        $this->assertFalse($policy->shouldApplyPeriod('projects', 88));
    }

    public function test_project_portfolio_without_selected_project_can_still_use_period(): void
    {
        $policy = new AssistantOperationalReportQueryPolicy;

        $this->assertTrue($policy->shouldApplyPeriod('projects', null));
    }

    public function test_project_owned_sections_keep_project_id_scoping(): void
    {
        $policy = new AssistantOperationalReportQueryPolicy;
        $hasColumn = static fn (string $table, string $column): bool => $table === 'contracts'
            && in_array($column, ['project_id', 'organization_id', 'created_at'], true);

        $this->assertSame('project_id', $policy->projectColumn('contracts', $hasColumn));
        $this->assertTrue($policy->shouldApplyPeriod('contracts', 88));
    }
}
