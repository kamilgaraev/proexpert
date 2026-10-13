<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantToolSurfaceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_handover_question_exposes_native_read_tool_without_actions(): void
    {
        $names = AssistantToolSurface::names(['assistant_domain_search', 'get_project_handover_summary', 'approve_payment_request'],
            ['request' => ['message' => 'Что по приёмке и передаче нашего объекта?', 'allow_actions' => false]]);
        self::assertContains('get_project_handover_summary', $names);
        self::assertNotContains('approve_payment_request', $names);
    }

    private const READ_TOOLS = ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
        'assistant_domain_discover_capabilities', 'search_projects', 'search_assistant_documents', 'get_estimate_answer',
        'search_estimate_positions', 'resolve_estimate', 'get_bim_model_elements', 'get_contract_snapshot',
        'get_live_project_financial_evidence', 'get_published_report_financial_evidence'];

    private const REPORT_TOOLS = ['generate_profitability_report', 'generate_work_completion_report',
        'generate_material_movements_report', 'generate_contractor_settlements_report', 'generate_warehouse_stock_report',
        'generate_time_tracking_report', 'generate_contract_payments_report', 'generate_project_timelines_report',
        'generate_operational_pdf_report', 'generate_rag_pdf_report'];

    public function test_generic_search_continuation_keeps_registered_paging_read_and_discovery_tools(): void
    {
        $registered = array_reverse(self::READ_TOOLS);
        $names = AssistantToolSurface::names($registered, ['request' => ['message' => 'Покажи следующую страницу']]);

        foreach (['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'assistant_domain_discover_capabilities'] as $name) {
            self::assertContains($name, $names);
        }
        self::assertSame(array_values(array_intersect($registered, $names)), $names);
        self::assertNotContains('get_estimate_answer', $names);
        self::assertNotContains('get_bim_model_elements', $names);
    }

    public function test_follow_up_inherits_specialized_estimate_tools_from_current_source_module(): void
    {
        $names = AssistantToolSurface::names(self::READ_TOOLS, ['request' => ['message' => 'Покажи ещё',
            'context' => ['source_module' => 'estimates']]]);

        self::assertContains('search_estimate_positions', $names);
        self::assertContains('resolve_estimate', $names);
        self::assertContains('get_estimate_answer', $names);
        self::assertNotContains('get_bim_model_elements', $names);
    }

    public function test_estimate_answer_keeps_registered_positions_and_financial_snapshot_dependencies(): void
    {
        $query = 'Покажи позиции сметы и её итоги';
        $names = AssistantToolSurface::names([...self::READ_TOOLS, 'get_estimate_positions', 'get_estimate_financial_snapshot'], [
            'capability' => ['id' => 'estimates'], 'request' => ['message' => $query, 'allow_actions' => false],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray(),
        ]);

        self::assertContains('get_estimate_answer', $names);
        self::assertContains('get_estimate_positions', $names);
        self::assertContains('get_estimate_financial_snapshot', $names);
    }

    public function test_pronoun_follow_up_keeps_selected_estimate_composition_tools(): void
    {
        $registered = [...self::READ_TOOLS, 'get_estimate_positions', 'get_estimate_financial_snapshot'];
        $plan = ['request' => ['message' => 'А её состав?', 'context' => ['source_module' => 'ai-assistant']]];
        self::assertNotContains('get_estimate_positions', AssistantToolSurface::names($registered, $plan));
        $names = AssistantToolSurface::names($registered, $plan, true);
        self::assertContains('get_estimate_positions', $names);
        self::assertContains('get_estimate_financial_snapshot', $names);
        self::assertNotContains('get_bim_model_elements', $names);
    }

    public function test_detail_follow_up_keeps_bim_reader_from_original_checked_search_query(): void
    {
        $names = AssistantToolSurface::names(self::READ_TOOLS, ['request' => ['message' => 'Подробнее о найденном',
            'context' => ['ui_state' => ['assistant_follow_up_query' => 'Найди IFC-модель вентиляции']]]]);

        self::assertContains('get_bim_model_elements', $names);
        self::assertNotContains('search_estimate_positions', $names);
    }

    public function test_payment_entities_preserve_financial_and_linked_contract_estimate_reads_without_bim(): void
    {
        $names = AssistantToolSurface::names(self::READ_TOOLS, ['request' => ['message' => 'Проверь актуальные данные'],
            'request_understanding' => ['requested_entities' => ['payment']]]);

        self::assertContains('get_live_project_financial_evidence', $names);
        self::assertContains('get_published_report_financial_evidence', $names);
        self::assertContains('get_contract_snapshot', $names);
        self::assertContains('get_estimate_answer', $names);
        self::assertContains('search_estimate_positions', $names);
        self::assertNotContains('get_bim_model_elements', $names);
    }

    public function test_procurement_capability_without_query_keeps_project_snapshot_dependency_only(): void
    {
        $names = AssistantToolSurface::names([...self::READ_TOOLS, 'get_procurement_snapshot', 'get_project_snapshot',
            'get_schedule_snapshot', ...self::REPORT_TOOLS, 'approve_payment_request'], ['capability' => ['id' => 'procurement'],
            'request' => ['allow_actions' => false]]);

        self::assertContains('get_procurement_snapshot', $names);
        self::assertContains('get_project_snapshot', $names);
        self::assertNotContains('get_bim_model_elements', $names);
        self::assertNotContains('approve_payment_request', $names);
        self::assertSame([], array_values(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_payment_dependency_closure_keeps_related_readers_and_locators_without_reports_or_mutations(): void
    {
        $registered = [...self::READ_TOOLS, 'get_project_snapshot', 'get_schedule_snapshot', 'get_material_stock',
            'search_materials', 'search_warehouse', 'get_estimate_positions', 'get_estimate_financial_snapshot',
            ...self::REPORT_TOOLS, 'approve_payment_request', 'create_schedule_task'];
        $names = AssistantToolSurface::names($registered, ['capability' => ['id' => 'payments'],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Что с платежами?', [])->toArray()]);

        foreach (['get_project_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot', 'get_material_stock',
            'search_materials', 'search_warehouse', 'get_estimate_answer', 'get_estimate_positions',
            'get_estimate_financial_snapshot'] as $tool) { self::assertContains($tool, $names); }
        self::assertNotContains('get_bim_model_elements', $names);
        self::assertNotContains('approve_payment_request', $names);
        self::assertNotContains('create_schedule_task', $names);
        self::assertSame([], array_values(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_report_intent_and_report_focus_select_required_tools_without_request_message(): void
    {
        $names = AssistantToolSurface::names([...self::READ_TOOLS, 'get_schedule_snapshot', 'get_project_snapshot',
            ...self::REPORT_TOOLS, 'approve_payment_request'], ['capability' => ['id' => 'reports'],
            'request' => ['context' => ['ui_state' => ['assistant_report_focus' => 'schedules']]],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Сделай отчёт по графику работ', [])->toArray()]);

        self::assertContains('get_schedule_snapshot', $names);
        self::assertContains('get_project_snapshot', $names);
        self::assertContains('generate_project_timelines_report', $names);
        self::assertNotContains('approve_payment_request', $names);
        self::assertLessThanOrEqual(2, count(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_report_surface_keeps_requested_report_and_bounded_fallback_without_all_report_schemas(): void
    {
        $registered = [...self::READ_TOOLS, ...self::REPORT_TOOLS];
        $names = AssistantToolSurface::names($registered, ['capability' => ['id' => 'profitability'],
            'request' => ['message' => 'Подготовь PDF-отчёт по прибыли']]);

        self::assertContains('generate_profitability_report', $names);
        self::assertNotContains('generate_time_tracking_report', $names);
        self::assertNotContains('generate_warehouse_stock_report', $names);
        self::assertLessThanOrEqual(3, count(array_intersect(self::REPORT_TOOLS, $names)));
        $generic = AssistantToolSurface::names($registered, ['capability' => ['id' => 'reports'],
            'request' => ['message' => 'Подготовь отчёт по проекту']]);
        self::assertContains('generate_operational_pdf_report', $generic);
        self::assertLessThanOrEqual(2, count(array_intersect(self::REPORT_TOOLS, $generic)));
    }

    public function test_large_catalog_compaction_retains_all_domain_identities_and_discovery_contract(): void
    {
        $hints = ['details_tool' => 'assistant_domain_discover_capabilities', 'record_access' => 'checked_on_read',
            'domains' => array_map(static fn (int $index): array => ['domain' => 'domain_'.$index,
                'primary_entity_type' => 'entity_'.$index, 'entity_type_count' => 24,
                'entity_types' => range(1, 24), 'fields' => ['private_secret'], 'operations' => ['search', 'read']], range(1, 83))];
        $compact = AssistantToolSurface::compactHints($hints);

        self::assertSame($hints['details_tool'], $compact['details_tool']);
        self::assertSame($hints['record_access'], $compact['record_access']);
        self::assertSame(array_column($hints['domains'], 'domain'), array_column($compact['domains'], 'domain'));
        self::assertCount(83, $compact['domains']);
        self::assertStringNotContainsString('private_secret', json_encode($compact, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('reportRequests')]
    public function test_each_registered_report_remains_available_for_its_explicit_request(string $capability, string $query, string $tool): void
    {
        $names = AssistantToolSurface::names([...self::READ_TOOLS, ...self::REPORT_TOOLS], [
            'capability' => ['id' => $capability], 'request' => ['message' => $query],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray(),
        ]);

        self::assertContains($tool, $names);
        self::assertLessThanOrEqual(3, count(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_allow_file_generation_does_not_expand_surface_to_all_registered_reports_or_mutations(): void
    {
        $query = 'Подготовь отчёт по проекту';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray();
        self::assertSame('allow_file_generation', $understanding['action_policy']);
        foreach ([false, true] as $allowActions) {
            $names = AssistantToolSurface::names([...self::READ_TOOLS, ...self::REPORT_TOOLS, 'approve_payment_request',
                'create_schedule_task'], ['capability' => ['id' => 'reports'], 'request' => ['message' => $query, 'allow_actions' => $allowActions],
                'request_understanding' => $understanding]);

            self::assertNotContains('approve_payment_request', $names);
            self::assertNotContains('create_schedule_task', $names);
            self::assertNotContains('get_bim_model_elements', $names);
            self::assertLessThanOrEqual(2, count(array_intersect(self::REPORT_TOOLS, $names)));
        }
    }

    public function test_navigation_policy_keeps_navigation_without_all_report_or_mutation_tools(): void
    {
        $query = 'Открой раздел проектов';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray();
        self::assertSame('allow_navigation', $understanding['action_policy']);
        $names = AssistantToolSurface::names([...self::READ_TOOLS, ...self::REPORT_TOOLS, 'create_schedule_task'], [
            'request' => ['message' => $query, 'allow_actions' => true], 'request_understanding' => $understanding,
        ]);

        self::assertContains('assistant_domain_navigation', $names);
        self::assertNotContains('create_schedule_task', $names);
        self::assertSame([], array_values(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_read_request_with_action_setting_does_not_receive_unrelated_tool_catalog(): void
    {
        $query = 'Покажи проект';
        $names = AssistantToolSurface::names([...self::READ_TOOLS, ...self::REPORT_TOOLS, 'create_schedule_task'], [
            'request' => ['message' => $query, 'allow_actions' => true],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray(),
        ]);

        self::assertContains('assistant_domain_read', $names);
        self::assertNotContains('get_bim_model_elements', $names);
        self::assertNotContains('create_schedule_task', $names);
        self::assertSame([], array_values(array_intersect(self::REPORT_TOOLS, $names)));
    }

    public function test_explicit_confirmable_mutation_keeps_its_registered_tool(): void
    {
        $query = 'Создай задачу графика';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray();
        self::assertSame('requires_confirmation', $understanding['action_policy']);
        $names = AssistantToolSurface::names([...self::READ_TOOLS, 'create_schedule_task'], [
            'request' => ['message' => $query, 'allow_actions' => true], 'request_understanding' => $understanding,
        ]);

        self::assertContains('create_schedule_task', $names);
    }

    public static function reportRequests(): array
    {
        return [
            ['profitability', 'Подготовь отчёт по прибыли', 'generate_profitability_report'],
            ['work_completion', 'Подготовь отчёт по выполненным работам', 'generate_work_completion_report'],
            ['material_movements', 'Подготовь отчёт по движению материалов', 'generate_material_movements_report'],
            ['contractor_settlements', 'Подготовь отчёт по взаиморасчётам с подрядчиками', 'generate_contractor_settlements_report'],
            ['warehouse_stock', 'Подготовь отчёт по остаткам склада', 'generate_warehouse_stock_report'],
            ['time_tracking', 'Подготовь отчёт по рабочему времени сотрудников', 'generate_time_tracking_report'],
            ['contract_payments', 'Подготовь отчёт по платежам договоров', 'generate_contract_payments_report'],
            ['project_timelines', 'Подготовь отчёт по срокам проектов', 'generate_project_timelines_report'],
            ['reports', 'Подготовь отчёт по проекту', 'generate_operational_pdf_report'],
            ['rag', 'Подготовь PDF по найденным документам', 'generate_rag_pdf_report'],
        ];
    }
}
