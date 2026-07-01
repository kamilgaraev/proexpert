<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportIntentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantReportIntentResolverTest extends TestCase
{
    #[DataProvider('reportIntentProvider')]
    public function test_resolves_report_type_from_prompt(string $message, string $expectedReportId): void
    {
        $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $message);

        $result = (new AssistantReportIntentResolver)->resolve($message);

        $this->assertSame('matched', $result['status']);
        $this->assertInstanceOf(AssistantReportDefinition::class, $result['definition'] ?? null);
        $this->assertSame($expectedReportId, $result['definition']->id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reportIntentProvider(): array
    {
        return [
            'profitability' => ['сформируй отчет по рентабельности за май', 'project_profitability'],
            'work completion' => ['нужен отчет по выполнению работ за прошлый месяц', 'work_completion'],
            'work completion dative' => ['сформируй отчет по выполненным работам', 'work_completion'],
            'work completion accepted works' => ['сделай отчет по закрытым объемам работ', 'work_completion'],
            'materials' => ['покажи движение материалов за 2 недели', 'material_movements'],
            'contractors' => ['сделай отчет по расчетам с подрядчиками за месяц', 'contractor_settlements'],
            'warehouse' => ['выгрузи складские остатки', 'warehouse_stock'],
            'time tracking' => ['подготовь отчет по трудозатратам за неделю', 'time_tracking'],
            'contract payments' => ['сформируй платежи по договорам за текущий год', 'contract_payments'],
            'timelines' => ['сделай отчет по графику работ за май', 'project_timelines'],
            'projects summary' => ['сделай сводку по проектам за месяц', 'projects_summary'],
            'procurement broad' => ['сделай отчет по закупкам для Лесного двора', 'procurement_requests'],
            'procurement supply' => ['сформируй отчет по снабжению объекта', 'procurement_requests'],
            'procurement requests' => ['подготовь отчет по заявкам на закупку за неделю', 'procurement_requests'],
            'purchase orders' => ['сформируй отчет по заказам поставщикам за квартал', 'purchase_orders'],
            'supplier proposals' => ['покажи отчет по предложениям поставщиков за май', 'supplier_proposals'],
            'site requests' => ['нужен отчет по заявкам со стройплощадки за месяц', 'site_requests'],
            'estimates summary' => ['сделай отчет по сметам и их статусам', 'estimates_summary'],
            'quality defects' => ['сформируй отчет по дефектам качества за месяц', 'quality_defects'],
            'safety incidents' => ['подготовь отчет по инцидентам безопасности за месяц', 'safety_incidents'],
            'machinery utilization' => ['покажи отчет по работе техники и простоям', 'machinery_utilization'],
            'workforce attendance' => ['сделай отчет по посещаемости сотрудников за неделю', 'workforce_attendance'],
        ];
    }

    public function test_resolves_cyrillic_prompt_after_transliteration(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('Сформируй отчет по графику работ за май');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('project_timelines', $result['definition']->id);
    }

    public function test_generic_report_request_asks_for_report_type(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('сформируй отчет за прошлый месяц');

        $this->assertSame('missing_type', $result['status']);
        $this->assertGreaterThan(3, count($result['candidates']));
    }

    public function test_generic_rag_report_request_matches_rag_pdf_report(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve(
            'Сформируй PDF-отчет по теме входной контроль качества из базы знаний'
        );

        $this->assertSame('matched', $result['status']);
        $this->assertSame('generic_rag', $result['definition']->id);
    }

    public function test_human_file_request_without_standard_report_type_matches_generic_rag_report(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve(
            'Сделай мне файл по Лесному двору, чтобы я мог быстро показать руководителю: текущее состояние, деньги, риски и ближайшие шаги.'
        );

        $this->assertSame('matched', $result['status']);
        $this->assertSame('generic_rag', $result['definition']->id);
    }

    public function test_unknown_thematic_report_request_matches_generic_rag_report(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve(
            'Сделай отчет по входному контролю кирпича для Лесного двора'
        );

        $this->assertSame('matched', $result['status']);
        $this->assertSame('generic_rag', $result['definition']->id);
    }

    public function test_report_recipient_without_topic_still_asks_for_report_type(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('Сделай отчет для руководителя');

        $this->assertSame('missing_type', $result['status']);
    }

    public function test_project_pdf_report_uses_project_summary_definition(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve(
            'Сформируй PDF-отчет по проекту Кирпичный дом Лесной двор'
        );

        $this->assertSame('matched', $result['status']);
        $this->assertSame('projects_summary', $result['definition']->id);
    }

    public function test_non_report_request_is_left_for_generic_assistant_flow(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('расскажи что ты умеешь');

        $this->assertSame('not_report', $result['status']);
    }

    #[DataProvider('negativeReportIntentProvider')]
    public function test_negative_report_and_file_constraints_are_left_for_generic_flow(string $message): void
    {
        $result = (new AssistantReportIntentResolver)->resolve($message);

        $this->assertSame('not_report', $result['status']);
        $this->assertArrayNotHasKey('definition', $result);
    }

    public function test_operational_report_term_without_report_marker_is_left_for_generic_flow(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('покажи потребность в закупках по объектам');

        $this->assertSame('not_report', $result['status']);
    }

    public function test_multi_domain_reconciliation_question_without_report_marker_is_left_for_rag_flow(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('Сравни договоры, сметы, работы и платежи: где есть расхождения или риски?');

        $this->assertSame('not_report', $result['status']);
        $this->assertArrayNotHasKey('definition', $result);
    }

    public function test_operational_report_term_with_report_marker_still_matches(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('сформируй отчет по потребности в закупках по объектам');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('procurement_requests', $result['definition']->id);
    }

    #[DataProvider('knowledgeContextQuestionProvider')]
    public function test_knowledge_context_questions_are_left_for_rag_flow(string $message): void
    {
        $result = (new AssistantReportIntentResolver)->resolve($message);

        $this->assertSame('not_report', $result['status']);
        $this->assertArrayNotHasKey('definition', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function knowledgeContextQuestionProvider(): array
    {
        return [
            'project knowledge summary' => ['Что ты знаешь из базы знаний по текущим проектам? Дай краткую сводку и укажи источники'],
            'requests needing attention' => ['Какие заявки или проблемы требуют внимания по данным из базы знаний?'],
            'contracts in context' => ['Какие договоры и суммы есть в контексте?'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeReportIntentProvider(): array
    {
        return [
            'exact production bug' => ['По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.'],
            'no actions no report' => ['Найди в базе знаний 3-5 фактов по проекту. Не выполняй действий, не создавай отчет, просто перечисли факты.'],
            'negative report word' => ['Без отчета расскажи, какие риски по проекту.'],
            'negative file word' => ['Не нужен файл, просто напиши текстом.'],
        ];
    }

    public function test_context_report_type_can_select_definition_for_report_like_request(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('сформируй за прошлый месяц', [
            'ui_state' => [
                'assistant_report_type' => 'contract_payments',
            ],
        ]);

        $this->assertSame('matched', $result['status']);
        $this->assertSame('contract_payments', $result['definition']->id);
    }
}
