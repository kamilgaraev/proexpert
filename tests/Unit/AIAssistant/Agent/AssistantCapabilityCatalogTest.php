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

    public function test_material_movements_report_capability_declares_excel_artifact(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->findById('report.material_movements');

        $this->assertIsArray($task);
        $this->assertSame('reports', $task['domain']);
        $this->assertSame('warehouse', $task['capability']);
        $this->assertSame('generate_material_movements_report', $task['tool_name']);
        $this->assertSame(['period'], $catalog->requiredSlotNames('report.material_movements'));
        $this->assertSame(['type' => 'excel'], $task['artifact']);
        $this->assertSame(['reports.view', 'admin.reports.view'], $task['read_permissions']);
    }

    public function test_catalog_can_match_material_movements_from_user_goal(): void
    {
        $catalog = new AssistantCapabilityCatalog;
        $task = $catalog->match('Покажи движение материалов за месяц', []);

        $this->assertIsArray($task);
        $this->assertSame('report.material_movements', $task['id']);
    }
}
