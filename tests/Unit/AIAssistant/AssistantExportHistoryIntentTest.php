<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExportHistoryReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use PHPUnit\Framework\TestCase;

final class AssistantExportHistoryIntentTest extends TestCase
{
    public function test_subscription_and_export_question_reads_both_scopes_without_capturing_other_domains(): void
    {
        $query = 'Есть ли у меня активные регулярные отчёты и есть ли по нашему объекту готовые выгрузки? Хочу понять, что уже можно посмотреть.';
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        self::assertSame('get_project_report_delivery_summary', AssistantOperationalReaderRegistry::intent($query)['name']);
        self::assertSame(['name' => 'get_project_report_delivery_summary', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertContains('get_project_report_delivery_summary', AssistantToolSurface::names(['get_project_report_delivery_summary'], $plan));
        self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, 'get_project_report_delivery_summary', ['project_id' => 999]));
        foreach ([$query.' И какие у меня права?', $query.' И что с бюджетом?', $query.' И сколько насосов в BIM?',
            $query.' И какие сделки CRM?', $query.' И что с охраной труда?', 'Создай регулярные отчёты и скачай готовые выгрузки.'] as $mixed) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($mixed));
        }
    }

    public function test_manager_history_questions_select_a_read_only_source_and_mixed_actions_do_not(): void
    {
        foreach (['Какие готовые выгрузки отчётов нашего объекта у нас есть? Какие ещё обрабатываются и какие завершились ошибкой?',
            'Покажи историю выгрузок отчётов объекта', 'Какие выгрузки ещё в очереди?'] as $query) {
            self::assertSame('get_project_export_history', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null, $query);
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => 'get_project_export_history', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
            self::assertContains('get_project_export_history', AssistantToolSurface::names(['get_project_export_history', 'get_project_report_catalog'], $plan));
            self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, 'get_project_export_history', ['project_id' => 999]));
        }
        foreach (['Скачай готовую выгрузку', 'Покажи готовые выгрузки и расходы проекта',
            'Какие готовые выгрузки и какие у меня уведомления?', 'Какие у меня права на отчёты и покажи готовую выгрузку?'] as $query) {
            self::assertFalse(AssistantProjectExportHistoryReader::matches($query), $query);
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
        }
    }
}
