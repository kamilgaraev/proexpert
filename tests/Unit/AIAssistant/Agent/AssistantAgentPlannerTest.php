<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantAgentPlannerTest extends TestCase
{
    public function test_pending_schedule_report_when_period_missing(): void
    {
        $decision = $this->planner()->decide(
            'Сделай отчет по графику работ',
            $this->projectContext(),
            null
        );

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('waiting_for_slots', $decision->state?->status);
        $this->assertSame('report.project_timelines', $decision->state?->id);
        $this->assertSame(['period'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame(56, $decision->state?->slotValue('project_id'));
        $this->assertSame('За какой период сформировать отчет?', $decision->clarificationQuestion);
    }

    #[DataProvider('periodFollowUpProvider')]
    public function test_period_reply_continues_pending_schedule_report(string $reply, string $dateFrom, string $dateTo): void
    {
        $decision = $this->planner()->decide(
            $reply,
            [],
            $this->pendingScheduleState()
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('ready_to_execute', $decision->state?->status);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame($reply, $decision->toolArguments['period']);
        $this->assertSame($dateFrom, $decision->toolArguments['date_from']);
        $this->assertSame($dateTo, $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function periodFollowUpProvider(): array
    {
        return [
            'last month' => ['за последний месяц', '2026-04-04', '2026-05-04'],
            'november' => ['за ноябрь', '2025-11-01', '2025-11-30'],
            'two months as words' => ['за два месяца', '2026-03-04', '2026-05-04'],
            'two weeks' => ['за 2 недели', '2026-04-20', '2026-05-04'],
            'three weeks ago' => ['3 недели назад', '2026-04-07', '2026-04-14'],
        ];
    }

    public function test_word_number_period_reply_continues_pending_work_completion_report(): void
    {
        $pending = new AssistantTaskState(
            id: 'report.work_completion',
            domain: 'reports',
            capability: 'reports',
            toolName: 'generate_work_completion_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'выполнение работ'
        );

        $decision = $this->planner()->decide('за два месяца', [], $pending);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_work_completion_report', $decision->toolName);
        $this->assertSame('за два месяца', $decision->toolArguments['period']);
        $this->assertSame('2026-03-04', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_last_months_period_reply_continues_pending_work_completion_report(): void
    {
        $pending = new AssistantTaskState(
            id: 'report.work_completion',
            domain: 'reports',
            capability: 'reports',
            toolName: 'generate_work_completion_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'выполнение работ'
        );

        $decision = $this->planner()->decide('за последние три месяца', [], $pending);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_work_completion_report', $decision->toolName);
        $this->assertSame('за последние три месяца', $decision->toolArguments['period']);
        $this->assertSame('2026-02-04', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_direct_work_completion_request_with_flexible_phrase_executes_immediately(): void
    {
        $decision = $this->planner()->decide(
            'сформируй отчет по выполненным работам за последние три месяца',
            $this->projectContext(),
            null
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_work_completion_report', $decision->toolName);
        $this->assertSame('2026-02-04', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_direct_request_with_period_executes_immediately(): void
    {
        $decision = $this->planner()->decide(
            'Сформируй отчет по графику работ за 2 недели',
            $this->projectContext(),
            null
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('ready_to_execute', $decision->state?->status);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame('2026-04-20', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_unknown_request_returns_answer_decision(): void
    {
        $decision = $this->planner()->decide('Расскажи, что умеешь', [], null);

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
        $this->assertNotSame('', $decision->clarificationQuestion);
    }

    public function test_operational_data_question_does_not_generate_pdf_report(): void
    {
        $decision = $this->planner()->decide('Покажи потребность в закупках по объектам', [], null);

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_text_summary_without_report_or_file_marker_does_not_generate_pdf_report(): void
    {
        $decision = $this->planner()->decide(
            'Покажи краткую сводку по движению материалов за две недели',
            $this->projectContext(),
            null
        );

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_multi_domain_reconciliation_question_uses_answer_flow_instead_of_payment_report(): void
    {
        $decision = $this->planner()->decide(
            'Сравни договоры, сметы, работы и платежи: где есть расхождения или риски?',
            $this->projectContext(),
            null
        );

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_knowledge_base_summary_uses_answer_flow_instead_of_report_tool(): void
    {
        $decision = $this->planner()->decide(
            'Что ты знаешь из базы знаний по текущим проектам? Дай краткую сводку и укажи источники',
            ['source_module' => 'ai-assistant'],
            null
        );

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
    }

    public function test_negative_pdf_report_request_uses_answer_flow_instead_of_report_tool(): void
    {
        $decision = $this->planner()->decide(
            'По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.',
            $this->projectContext(),
            null
        );

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_project_id_is_optional_for_schedule_report(): void
    {
        $decision = $this->planner()->decide(
            'Сделай отчет по графику работ за ноябрь',
            ['source_module' => 'ai-assistant', 'entity_refs' => []],
            null
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame('2025-11-01', $decision->toolArguments['date_from']);
        $this->assertSame('2025-11-30', $decision->toolArguments['date_to']);
        $this->assertArrayNotHasKey('project_id', $decision->toolArguments);
    }

    public function test_generic_report_request_asks_for_report_type_without_llm_execution(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет за прошлый месяц', [], null);

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('report.unspecified', $decision->state?->id);
        $this->assertSame(['report_type'], $decision->state?->missingRequiredSlotNames());
        $this->assertStringContainsString('Какой отчет', (string) $decision->clarificationQuestion);
    }

    public function test_generic_rag_pdf_report_request_executes_with_source_query(): void
    {
        $message = 'Сформируй PDF-отчет по теме входной контроль качества из базы знаний';

        $decision = $this->planner()->decide($message, $this->projectContext(), null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_rag_pdf_report', $decision->toolName);
        $this->assertSame('generic_rag', $decision->toolArguments['report_type']);
        $this->assertSame($message, $decision->toolArguments['query']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_report_type_follow_up_resumes_unspecified_report_request(): void
    {
        $pending = new AssistantTaskState(
            id: 'report.unspecified',
            domain: 'reports',
            capability: 'reports',
            toolName: '',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('report_type', true),
            ],
            sourceMessage: 'Сформируй отчет за прошлый месяц'
        );

        $decision = $this->planner()->decide('движение материалов', [], $pending);

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('report.material_movements', $decision->state?->id);
        $this->assertSame(['period'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame('generate_material_movements_report', $decision->state?->toolName);
    }

    public function test_any_report_follow_up_uses_default_operational_report(): void
    {
        $pending = new AssistantTaskState(
            id: 'report.unspecified',
            domain: 'reports',
            capability: 'reports',
            toolName: '',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('report_type', true),
            ],
            sourceMessage: 'Сформируй отчет за прошлый месяц'
        );

        $decision = $this->planner()->decide('любые', [], $pending);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('report.projects_summary', $decision->state?->id);
        $this->assertSame('generate_operational_pdf_report', $decision->toolName);
        $this->assertSame('projects_summary', $decision->toolArguments['report_type']);
        $this->assertSame('2026-04-01', $decision->toolArguments['date_from']);
        $this->assertSame('2026-04-30', $decision->toolArguments['date_to']);
    }

    public function test_warehouse_stock_report_executes_without_period(): void
    {
        $decision = $this->planner()->decide('Выгрузи отчет по остаткам склада', [
            'entity_refs' => [
                ['type' => 'warehouse', 'id' => 7, 'label' => 'Основной склад'],
            ],
        ], null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_warehouse_stock_report', $decision->toolName);
        $this->assertSame(7, $decision->toolArguments['warehouse_id']);
        $this->assertArrayNotHasKey('date_from', $decision->toolArguments);
    }

    private function planner(): AssistantAgentPlanner
    {
        return new AssistantAgentPlanner(
            new AssistantCapabilityCatalog,
            new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function projectContext(): array
    {
        return [
            'source_module' => 'ai-assistant',
            'entity_refs' => [
                ['type' => 'project', 'id' => 56, 'label' => 'Строительство склада Литер А'],
            ],
        ];
    }

    private function pendingScheduleState(): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.project_timelines',
            domain: 'reports',
            capability: 'schedules',
            toolName: 'generate_project_timelines_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'Сделай отчет по графику работ'
        );
    }
}
