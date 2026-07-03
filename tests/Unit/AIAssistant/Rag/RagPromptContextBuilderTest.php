<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use DateTimeImmutable;
use Tests\TestCase;

class RagPromptContextBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-assistant.rag.max_chunks', 8);
    }

    public function test_builds_prompt_and_metadata_from_results(): void
    {
        $builder = new RagPromptContextBuilder;
        $result = new RagSearchResult(
            sourceType: 'schedule',
            entityType: 'schedule',
            entityId: '56',
            projectId: 10,
            title: 'Schedule source',
            excerpt: 'Concrete evidence from schedule.',
            similarity: 0.84321,
            metadata: ['status' => 'active'],
            updatedAt: new DateTimeImmutable('2026-05-23T10:00:00+03:00')
        );

        $context = $builder->build('what is blocked', [$result]);

        $this->assertStringContainsString('МОСТ', $context['prompt']);
        $this->assertStringContainsString('Проблема — что не так — что сделать', $context['prompt']);
        $this->assertStringContainsString('расхождений и рисков', $context['prompt']);
        $this->assertStringContainsString('не подменяй их проектными сметами', $context['prompt']);
        $this->assertStringContainsString('есть признаки проблемы', $context['prompt']);
        $this->assertStringContainsString('[1] Schedule source: Concrete evidence from schedule.', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertTrue($context['metadata']['used']);
        $this->assertSame('what is blocked', $context['metadata']['query']);
        $this->assertSame(8, $context['metadata']['limits']['requested']);
        $this->assertSame(1, $context['metadata']['limits']['returned']);
        $this->assertSame('schedule', $context['metadata']['sources'][0]['source_type']);
        $this->assertSame(0.8432, $context['metadata']['sources'][0]['score']);
        $this->assertSame('2026-05-23T10:00:00+03:00', $context['metadata']['sources'][0]['updated_at']);
        $this->assertSame('/projects/10/schedules/56', $context['metadata']['sources'][0]['navigation_target']['route']);
        $this->assertSame('Schedule source', $context['metadata']['sources'][0]['navigation_target']['state']['assistant_source']['title']);
    }

    public function test_empty_results_mark_context_unused_without_sources(): void
    {
        $context = (new RagPromptContextBuilder)->build('missing context', []);

        $this->assertSame('', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([], $context['metadata']['sources']);
        $this->assertSame(0, $context['metadata']['limits']['returned']);
    }

    public function test_builds_navigation_targets_for_expanded_sources(): void
    {
        config()->set('ai-assistant.rag.max_chunks', 80);

        $cases = [
            ['estimate', '55', 10, [], '/projects/10/estimates/55'],
            ['estimate_section', '56', 10, ['estimate_id' => 55], '/projects/10/estimates/55'],
            ['estimate_template', '7', null, [], '/templates/library'],
            ['estimate_library_item', '8', null, [], '/libraries'],
            ['normative_rate', '9', null, [], '/catalogs/estimate-positions'],
            ['estimate_catalog_item', '11', null, [], '/catalogs/estimate-positions'],
            ['construction_journal_entry', '21', 10, ['journal_id' => 3], '/journals/3/entries/21'],
            ['performance_act', '31', 10, [], '/acts/31'],
            ['payment_document', '41', 10, [], '/payments/documents/41'],
            ['quality_defect', '51', 10, [], '/quality-control/defects/51'],
            ['executive_document_set', '61', 10, [], '/executive-documentation/sets/61'],
            ['executive_document', '71', 10, ['document_set_id' => 6], '/executive-documentation/sets/6'],
            ['project_material_delivery', '81', 10, [], '/warehouse'],
            ['warehouse_balance', '82', 10, [], '/warehouse'],
            ['warehouse_movement', '83', 10, [], '/warehouse'],
            ['warehouse_project_allocation', '84', 10, [], '/warehouse'],
            ['asset_reservation', '85', 10, [], '/warehouse'],
            ['inventory_act', '86', null, [], '/warehouse'],
            ['warehouse_storage_cell', '87', null, [], '/warehouse'],
            ['warehouse_task', '88', 10, [], '/warehouse'],
            ['warehouse_asset', '89', 10, [], '/warehouse'],
            ['schedule_task', '90', 10, [], '/projects/10/schedules'],
            ['supplier_request', '91', 10, [], '/procurement'],
            ['supplier_proposal', '92', 10, [], '/procurement'],
            ['supplier_proposal_decision', '93', 10, [], '/procurement'],
            ['purchase_order', '94', 10, [], '/procurement'],
            ['purchase_receipt', '95', 10, [], '/procurement'],
            ['procurement_approval', '96', 10, [], '/procurement'],
            ['procurement_audit_event', '97', 10, [], '/procurement'],
            ['safety_incident', '101', 10, [], '/safety'],
            ['safety_violation', '102', 10, [], '/safety'],
            ['safety_work_permit', '103', 10, [], '/safety'],
            ['safety_briefing', '104', 10, [], '/safety'],
            ['safety_corrective_action', '105', 10, [], '/safety'],
            ['machinery_asset', '111', 10, [], '/machinery'],
            ['machinery_assignment', '112', 10, [], '/machinery'],
            ['machinery_shift_report', '113', 10, [], '/machinery'],
            ['machinery_downtime', '114', 10, [], '/machinery'],
            ['machinery_maintenance_order', '115', 10, [], '/machinery'],
            ['machinery_fuel_issue', '116', 10, [], '/machinery'],
            ['machinery_production_record', '117', 10, [], '/machinery'],
            ['production_labor_work_order', '121', 10, [], '/production-labor'],
            ['production_labor_work_order_line', '122', 10, [], '/production-labor'],
            ['production_labor_timesheet', '123', 10, [], '/production-labor'],
            ['production_labor_timesheet_entry', '124', 10, [], '/production-labor'],
            ['production_labor_output_entry', '125', 10, [], '/production-labor'],
            ['production_labor_payroll_accrual', '126', 10, [], '/production-labor'],
            ['change_management_rfi', '131', 10, [], '/change-management'],
            ['change_request', '132', 10, [], '/change-management'],
            ['change_claim', '133', 10, [], '/change-management'],
            ['change_impact', '134', 10, [], '/change-management'],
            ['change_approval', '135', 10, [], '/change-management'],
            ['variation_order', '136', 10, [], '/change-management'],
            ['project_location', '141', 10, [], '/handover-acceptance'],
            ['acceptance_scope', '142', 10, [], '/handover-acceptance'],
            ['acceptance_session', '143', 10, [], '/handover-acceptance'],
            ['acceptance_checklist', '144', 10, [], '/handover-acceptance'],
            ['acceptance_checklist_item', '145', 10, [], '/handover-acceptance'],
            ['acceptance_finding', '146', 10, [], '/handover-acceptance'],
            ['acceptance_signoff', '147', 10, [], '/handover-acceptance'],
            ['handover_package', '148', 10, [], '/handover-acceptance'],
            ['handover_package_document', '149', 10, [], '/handover-acceptance'],
        ];

        $results = array_map(
            static fn (array $case): RagSearchResult => new RagSearchResult(
                sourceType: 'test',
                entityType: $case[0],
                entityId: $case[1],
                projectId: $case[2],
                title: "Source {$case[0]}",
                excerpt: 'Evidence',
                similarity: 0.8,
                metadata: $case[3],
                updatedAt: null
            ),
            $cases
        );

        $context = (new RagPromptContextBuilder)->build('open source', $results);

        foreach ($cases as $index => $case) {
            $this->assertSame($case[4], $context['metadata']['sources'][$index]['navigation_target']['route']);
        }
    }
}
