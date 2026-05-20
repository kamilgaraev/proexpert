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
            'materials' => ['покажи движение материалов за 2 недели', 'material_movements'],
            'contractors' => ['сделай отчет по расчетам с подрядчиками за месяц', 'contractor_settlements'],
            'warehouse' => ['выгрузи складские остатки', 'warehouse_stock'],
            'time tracking' => ['подготовь отчет по трудозатратам за неделю', 'time_tracking'],
            'contract payments' => ['сформируй платежи по договорам за текущий год', 'contract_payments'],
            'timelines' => ['сделай отчет по графику работ за май', 'project_timelines'],
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

    public function test_non_report_request_is_left_for_generic_assistant_flow(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('расскажи что ты умеешь');

        $this->assertSame('not_report', $result['status']);
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
