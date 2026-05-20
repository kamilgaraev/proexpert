<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportCatalog;
use PHPUnit\Framework\TestCase;

final class AssistantReportCatalogTest extends TestCase
{
    public function test_catalog_covers_all_registered_report_tools(): void
    {
        $catalog = new AssistantReportCatalog;

        $this->assertSame([
            'generate_profitability_report',
            'generate_work_completion_report',
            'generate_material_movements_report',
            'generate_contractor_settlements_report',
            'generate_warehouse_stock_report',
            'generate_time_tracking_report',
            'generate_contract_payments_report',
            'generate_project_timelines_report',
            'generate_operational_pdf_report',
        ], $catalog->toolNames());
    }

    public function test_catalog_contains_additional_operational_pdf_reports(): void
    {
        $catalog = new AssistantReportCatalog;

        $this->assertCount(18, $catalog->all());

        foreach ([
            'projects_summary',
            'procurement_requests',
            'purchase_orders',
            'supplier_proposals',
            'site_requests',
            'estimates_summary',
            'quality_defects',
            'safety_incidents',
            'machinery_utilization',
            'workforce_attendance',
        ] as $reportId) {
            $definition = $catalog->findById($reportId);

            $this->assertNotNull($definition, "Report {$reportId} must be registered");
            $this->assertSame('generate_operational_pdf_report', $definition->toolName);
            $this->assertSame('pdf', $definition->artifactType);
            $this->assertSame('pdf', $definition->defaultFormat);
            $this->assertSame(['pdf'], $definition->formats);
        }
    }

    public function test_every_definition_has_operational_metadata(): void
    {
        $catalog = new AssistantReportCatalog;

        foreach ($catalog->all() as $definition) {
            $this->assertNotSame('', $definition->id);
            $this->assertNotSame('', $definition->label);
            $this->assertNotSame('', $definition->toolName);
            $this->assertNotSame([], $definition->aliases);
            $this->assertNotSame([], $definition->matchTerms);
            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $definition->label);
            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', implode(' ', $definition->aliases));
            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', implode(' ', $definition->matchTerms));
            $this->assertNotSame([], $definition->permissions);
            $this->assertNotSame('', $definition->artifactType);
            $this->assertContains($definition->defaultFormat, $definition->formats);
        }
    }

    public function test_catalog_exports_agent_task_shape(): void
    {
        $catalog = new AssistantReportCatalog;
        $task = $catalog->findById('project_timelines')?->toAgentTask();

        $this->assertIsArray($task);
        $this->assertSame('report.project_timelines', $task['id']);
        $this->assertSame('reports', $task['domain']);
        $this->assertSame('generate_project_timelines_report', $task['tool_name']);
        $this->assertSame(['type' => 'pdf'], $task['artifact']);
    }
}
