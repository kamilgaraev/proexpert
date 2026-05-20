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
            'profitability' => ['sformiruy otchet po rentabelnosti za may', 'project_profitability'],
            'work completion' => ['nuzhen otchet po vypolneniyu rabot za proshlyy mesyats', 'work_completion'],
            'materials' => ['pokazhi dvizhenie materialov za 2 nedeli', 'material_movements'],
            'contractors' => ['sdelay otchet po raschetam s podryadchikami za mesyats', 'contractor_settlements'],
            'warehouse' => ['vygruzi skladskie ostatki', 'warehouse_stock'],
            'time tracking' => ['podgotov otchet po trudozatratam za nedelyu', 'time_tracking'],
            'contract payments' => ['sformiruy platezhi po dogovoram za tekuschiy god', 'contract_payments'],
            'timelines' => ['sdelay otchet po grafiku rabot za may', 'project_timelines'],
        ];
    }

    public function test_resolves_cyrillic_prompt_after_transliteration(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve($this->ru('\u0421\u0444\u043e\u0440\u043c\u0438\u0440\u0443\u0439 \u043e\u0442\u0447\u0435\u0442 \u043f\u043e \u0433\u0440\u0430\u0444\u0438\u043a\u0443 \u0440\u0430\u0431\u043e\u0442 \u0437\u0430 \u043c\u0430\u0439'));

        $this->assertSame('matched', $result['status']);
        $this->assertSame('project_timelines', $result['definition']->id);
    }

    public function test_generic_report_request_asks_for_report_type(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('sformiruy otchet za proshlyy mesyats');

        $this->assertSame('missing_type', $result['status']);
        $this->assertGreaterThan(3, count($result['candidates']));
    }

    public function test_non_report_request_is_left_for_generic_assistant_flow(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('rasskazhi chto ty umeesh');

        $this->assertSame('not_report', $result['status']);
    }

    public function test_context_report_type_can_select_definition_for_report_like_request(): void
    {
        $result = (new AssistantReportIntentResolver)->resolve('sformiruy za proshlyy mesyats', [
            'ui_state' => [
                'assistant_report_type' => 'contract_payments',
            ],
        ]);

        $this->assertSame('matched', $result['status']);
        $this->assertSame('contract_payments', $result['definition']->id);
    }

    private function ru(string $escaped): string
    {
        $decoded = json_decode('"'.$escaped.'"');

        $this->assertIsString($decoded);

        return $decoded;
    }
}
