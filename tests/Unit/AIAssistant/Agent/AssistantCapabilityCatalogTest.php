<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use PHPUnit\Framework\TestCase;

class AssistantCapabilityCatalogTest extends TestCase
{
    public function test_schedule_report_capability_declares_required_period_and_project_context(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->findById('report.project_timelines');

        $this->assertIsArray($task);
        $this->assertSame('reports', $task['domain']);
        $this->assertSame('schedules', $task['capability']);
        $this->assertSame('generate_project_timelines_report', $task['tool_name']);
        $this->assertSame(['period'], $catalog->requiredSlotNames('report.project_timelines'));
        $this->assertContains('project_id', $catalog->optionalSlotNames('report.project_timelines'));
        $this->assertSame(['reports.view', 'schedule-management.view', 'admin.reports.view'], $task['read_permissions']);
    }

    public function test_catalog_can_match_schedule_report_from_user_goal(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->match('Сделай отчет по графику работ', [
            'source_module' => 'ai-assistant',
            'entity_refs' => [
                ['type' => 'project', 'id' => 56, 'label' => 'Строительство склада Литер А'],
            ],
        ]);

        $this->assertIsArray($task);
        $this->assertSame('report.project_timelines', $task['id']);
    }

    public function test_material_movements_report_capability_declares_pdf_artifact(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->findById('report.material_movements');

        $this->assertIsArray($task);
        $this->assertSame('reports', $task['domain']);
        $this->assertSame('warehouse', $task['capability']);
        $this->assertSame('generate_material_movements_report', $task['tool_name']);
        $this->assertSame(['period'], $catalog->requiredSlotNames('report.material_movements'));
        $this->assertSame(['type' => 'pdf'], $task['artifact']);
        $this->assertSame(['reports.view', 'admin.reports.view'], $task['read_permissions']);
    }

    public function test_catalog_can_match_material_movements_from_user_goal(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->match('Покажи движение материалов за месяц', []);

        $this->assertIsArray($task);
        $this->assertSame('report.material_movements', $task['id']);
    }

    public function test_catalog_exposes_all_ai_report_tools(): void
    {
        $catalog = new AssistantCapabilityCatalog;

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
            'generate_rag_pdf_report',
        ], array_values(array_unique(array_column($catalog->all(), 'tool_name'))));
    }

    public function test_generic_report_request_does_not_default_to_schedule_report(): void
    {
        $catalog = new AssistantCapabilityCatalog;

        $this->assertNull($catalog->match('Сформируй отчет за прошлый месяц', [
            'source_module' => 'reports',
        ]));
    }
}
