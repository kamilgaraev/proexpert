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
    use \Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

    public function test_pending_schedule_report_when_period_missing(): void
    {
        $decision = $this->decide(
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
        $decision = $this->decide(
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

        $decision = $this->decide('за два месяца', [], $pending);

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

        $decision = $this->decide('за последние три месяца', [], $pending);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_work_completion_report', $decision->toolName);
        $this->assertSame('за последние три месяца', $decision->toolArguments['period']);
        $this->assertSame('2026-02-04', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_project_start_period_reply_continues_pending_contract_payments_report(): void
    {
        $decision = $this->decide(
            'с начала проекта по сегодняшний день',
            [],
            $this->pendingContractPaymentsState()
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('ready_to_execute', $decision->state?->status);
        $this->assertSame('generate_contract_payments_report', $decision->toolName);
        $this->assertSame('с начала проекта по сегодняшний день', $decision->toolArguments['period']);
        $this->assertArrayNotHasKey('date_from', $decision->toolArguments);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_short_year_period_reply_continues_pending_contract_payments_report(): void
    {
        $decision = $this->decide(
            'за год',
            [],
            $this->pendingContractPaymentsState()
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_contract_payments_report', $decision->toolName);
        $this->assertSame('за год', $decision->toolArguments['period']);
        $this->assertSame('2025-05-04', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_direct_work_completion_request_with_flexible_phrase_executes_immediately(): void
    {
        $decision = $this->decide(
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

    public function test_send_work_completion_report_without_period_asks_for_period(): void
    {
        $decision = $this->decide(
            'Отправь отчет по выполненным работам',
            $this->projectContext(),
            null
        );

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('report.work_completion', $decision->state?->id);
        $this->assertSame(['period'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame(56, $decision->state?->slotValue('project_id'));
        $this->assertSame('За какой период сформировать отчет?', $decision->clarificationQuestion);
    }

    public function test_direct_request_with_period_executes_immediately(): void
    {
        $decision = $this->decide(
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
        $decision = $this->decide('Расскажи, что умеешь', [], null);

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
        $this->assertNotSame('', $decision->clarificationQuestion);
    }

    public function test_operational_data_question_does_not_generate_pdf_report(): void
    {
        $decision = $this->decide('Покажи потребность в закупках по объектам', [], null);

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_text_summary_without_report_or_file_marker_does_not_generate_pdf_report(): void
    {
        $decision = $this->decide(
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
        $decision = $this->decide(
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
        $decision = $this->decide(
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
        $decision = $this->decide(
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
        $decision = $this->decide(
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
        $decision = $this->decide('Сформируй отчет за прошлый месяц', [], null);

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('report.unspecified', $decision->state?->id);
        $this->assertSame(['report_type'], $decision->state?->missingRequiredSlotNames());
        $this->assertStringContainsString('Какой отчет', (string) $decision->clarificationQuestion);
    }

    public function test_generic_rag_pdf_report_request_executes_with_source_query(): void
    {
        $message = 'Сформируй PDF-отчет по теме входной контроль качества из базы знаний';

        $decision = $this->decide($message, $this->projectContext(), null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_rag_pdf_report', $decision->toolName);
        $this->assertSame('generic_rag', $decision->toolArguments['report_type']);
        $this->assertSame($message, $decision->toolArguments['query']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_broad_procurement_report_request_executes_operational_procurement_report(): void
    {
        $message = 'Сделай отчет по закупкам для Лесного двора';

        $decision = $this->decide($message, $this->projectContext(), null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_operational_pdf_report', $decision->toolName);
        $this->assertSame('procurement_requests', $decision->toolArguments['report_type']);
        $this->assertSame($message, $decision->toolArguments['query']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_unknown_thematic_report_request_executes_generic_rag_pdf_report(): void
    {
        $message = 'Сделай отчет по входному контролю кирпича для Лесного двора';

        $decision = $this->decide($message, $this->projectContext(), null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_rag_pdf_report', $decision->toolName);
        $this->assertSame('generic_rag', $decision->toolArguments['report_type']);
        $this->assertSame($message, $decision->toolArguments['query']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_human_file_request_executes_generic_rag_pdf_report(): void
    {
        $message = 'Сделай мне файл по Лесному двору, чтобы я мог быстро показать руководителю: текущее состояние, деньги, риски и ближайшие шаги.';

        $decision = $this->decide($message, $this->projectContext(), null);

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

        $decision = $this->decide('движение материалов', [], $pending);

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

        $decision = $this->decide('любые', [], $pending);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('report.projects_summary', $decision->state?->id);
        $this->assertSame('generate_operational_pdf_report', $decision->toolName);
        $this->assertSame('projects_summary', $decision->toolArguments['report_type']);
        $this->assertSame('2026-04-01', $decision->toolArguments['date_from']);
        $this->assertSame('2026-04-30', $decision->toolArguments['date_to']);
    }

    public function test_warehouse_stock_report_executes_without_period(): void
    {
        $decision = $this->decide('Выгрузи отчет по остаткам склада', [
            'entity_refs' => [
                ['type' => 'warehouse', 'id' => 7, 'label' => 'Основной склад'],
            ],
        ], null);

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_warehouse_stock_report', $decision->toolName);
        $this->assertSame(7, $decision->toolArguments['warehouse_id']);
        $this->assertArrayNotHasKey('date_from', $decision->toolArguments);
    }

    public function test_named_project_is_resolved_before_report_instead_of_ui_project(): void
    {
        $calls = [];
        $decision = $this->decide(
            'Сформируй PDF отчет по движению материалов проекта СевГУ за ноябрь 2026',
            $this->projectContext(),
            null,
            static function (string $type, ?string $query, ?int $id) use (&$calls): ?array {
                $calls[] = [$type, $query, $id];
                return ['id' => 72, 'label' => 'СевГУ'];
            }
        );

        $this->assertSame([['project', 'СевГУ', null]], $calls);
        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame(72, $decision->toolArguments['project_id']);
        $this->assertSame('2026-11-01', $decision->toolArguments['date_from']);
        $this->assertSame('2026-11-30', $decision->toolArguments['date_to']);
    }

    #[DataProvider('namedScopeProvider')]
    public function test_named_scope_is_required_and_cannot_expand_to_organization(string $request, string $type, string $name, string $slot): void
    {
        $decision = $this->planner()->decide($request, [], null,
            function (string $actualType, ?string $query, ?int $id) use ($type, $name): ?array {
                $this->assertSame($type, $actualType);
                $this->assertSame($name, $query);
                $this->assertNull($id);
                return null;
            });
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame([$slot], $decision->state?->missingRequiredSlotNames());
        $this->assertSame([], $decision->toolArguments);
        $this->assertNotEmpty($decision->clarificationQuestion);
    }

    public static function namedScopeProvider(): array
    {
        return [
            'project' => ['Сформируй отчет по графику работ проекта СевГУ за ноябрь 2026', 'project', 'СевГУ', 'project_id'],
            'warehouse' => ['Выгрузи отчет по остаткам склада Основной', 'warehouse', 'Основной', 'warehouse_id'],
            'contractor' => ['Сформируй отчет по взаиморасчетам подрядчика СтройСервис за ноябрь 2026', 'contractor', 'СтройСервис', 'contractor_id'],
            'user' => ['Сформируй отчет по трудозатратам сотрудника Иванов за ноябрь 2026', 'user', 'Иванов', 'user_id'],
        ];
    }

    public function test_context_id_is_not_trusted_without_authorized_resolution(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет по графику работ за ноябрь 2026', $this->projectContext());
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['project_id'], $decision->state?->missingRequiredSlotNames());
        $this->assertNull($decision->state?->slotValue('project_id'));
    }

    public function test_fractional_context_id_cannot_be_coerced_to_another_project(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет по графику работ за ноябрь 2026', [
            'entity_refs' => [['type' => 'project', 'id' => '56.9', 'label' => 'СевГУ']],
        ], null, static fn (): array => ['id' => 56, 'label' => 'Другое название']);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertNull($decision->state?->slotValue('project_id'));
    }

    public function test_saved_project_is_revalidated_after_access_revocation(): void
    {
        $decision = $this->planner()->decide('за ноябрь 2026', [], $this->pendingScheduleState(), static fn (): ?array => null);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['project_id'], $decision->state?->missingRequiredSlotNames());
        $this->assertNull($decision->state?->slotValue('project_id'));
    }

    public function test_organization_report_without_entity_scope_keeps_organization_scope(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет по движению материалов за ноябрь 2026 по всей организации', $this->projectContext());
        $this->assertSame('execute_tool', $decision->type);
        $this->assertArrayNotHasKey('project_id', $decision->toolArguments);
    }

    public function test_unsupported_named_scope_cannot_be_ignored_by_report(): void
    {
        $decision = $this->planner()->decide('Выгрузи отчет по остаткам склада проекта СевГУ', []);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['__unsupported_scope'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_named_scope_survives_period_follow_up_and_scope_change(): void
    {
        $initial = $this->decide('Сформируй отчет по графику работ проекта СевГУ', [], null,
            static fn (): array => ['id' => 72, 'label' => 'СевГУ']);
        $this->assertSame('ask_clarification', $initial->type);
        $changed = $this->decide('проекта Корпус Б', [], $initial->state,
            static fn (): array => ['id' => 73, 'label' => 'Корпус Б']);
        $decision = $this->decide('за ноябрь 2024', [], $changed->state,
            function (string $type, ?string $query, ?int $id): array {
                $this->assertSame('Корпус Б', $query);
                $this->assertNull($id);
                return ['id' => 73, 'label' => 'Корпус Б'];
            });
        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame(73, $decision->toolArguments['project_id']);
        $this->assertSame('2024-11-01', $decision->toolArguments['date_from']);
    }

    public function test_current_project_without_context_cannot_become_organization_report(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет по движению материалов текущего проекта за ноябрь 2026', []);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['project_id'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame([], $decision->toolArguments);
    }

    public function test_current_project_uses_only_authorized_context_id(): void
    {
        $calls = [];
        $decision = $this->planner()->decide('Сформируй отчет по движению материалов текущего проекта за ноябрь 2026', $this->projectContext(), null,
            static function (string $type, ?string $query, ?int $id) use (&$calls): ?array {
                $calls[] = [$type, $query, $id];
                return ['id' => 56, 'label' => 'Проверенный проект'];
            });
        $this->assertSame([['project', null, 56]], $calls);
        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_current_warehouse_without_context_requires_scope(): void
    {
        $decision = $this->planner()->decide('Выгрузи отчет по остаткам текущего склада', []);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['warehouse_id'], $decision->state?->missingRequiredSlotNames());
    }

    public function test_current_warehouse_requires_current_authorized_context(): void
    {
        $context = ['entity_refs' => [['type' => 'warehouse', 'id' => 7, 'label' => 'Текущий склад']]];
        $decision = $this->planner()->decide('Выгрузи отчет по остаткам текущего склада', $context, null,
            static fn (string $type, ?string $query, ?int $id): ?array => $type === 'warehouse' && $query === null && $id === 7
                ? ['id' => 7, 'label' => 'Проверенный склад'] : null);
        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame(7, $decision->toolArguments['warehouse_id']);
        $denied = $this->planner()->decide('Выгрузи отчет по остаткам текущего склада', $context, null, static fn (): ?array => null);
        $this->assertSame('ask_clarification', $denied->type);
        $this->assertSame([], $denied->toolArguments);
    }

    public function test_current_contract_cannot_be_ignored_when_report_has_no_contract_filter(): void
    {
        $decision = $this->planner()->decide('Сформируй отчет по платежам договоров для текущего договора за ноябрь 2026', []);
        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame(['__unsupported_scope'], $decision->state?->missingRequiredSlotNames());
    }

    private function decide(string $message, array $context, ?AssistantTaskState $pending = null, ?callable $resolver = null): \App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantAgentDecision
    {
        return $this->planner()->decide($message, $context, $pending, $resolver ?? static fn (string $type, ?string $query, ?int $id): ?array =>
            $id !== null ? ['id' => $id, 'label' => null] : null);
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

    private function pendingContractPaymentsState(): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.contract_payments',
            domain: 'reports',
            capability: 'payments',
            toolName: 'generate_contract_payments_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'Платежи по договорам'
        );
    }
}
