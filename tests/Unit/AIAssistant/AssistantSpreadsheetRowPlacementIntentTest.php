<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactLabels;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantSpreadsheetRowPlacementIntentTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_site_request_labels_follow_native_values_and_preserve_unknown_codes(): void
    {
        self::assertSame('Одобрена', AssistantStructuredFactLabels::display('site_request', 'status', 'approved'));
        self::assertSame('Срочно', AssistantStructuredFactLabels::display('site_request', 'priority', 'urgent'));
        self::assertSame('Заявка на материалы', AssistantStructuredFactLabels::display('site_request', 'request_type', 'material_request'));
        self::assertSame('future_status', AssistantStructuredFactLabels::display('site_request', 'status', 'future_status'));
    }

    #[DataProvider('siteRequestTypes')]
    public function test_site_request_type_labels_follow_native_legacy_normalization(string $stored, string $label): void
    {
        self::assertSame($label, AssistantStructuredFactLabels::display('site_request', 'request_type', $stored));
    }

    public static function siteRequestTypes(): array
    {
        return [
            ['material_request', 'Заявка на материалы'],
            ['personnel_request', 'Заявка на персонал'],
            ['equipment_request', 'Заявка на технику'],
            ['info_request', 'Запрос информации'],
            ['issue_report', 'Сообщение о проблеме'],
            ['other', 'Другое'],
            ['material', 'Заявка на материалы'],
            ['personnel', 'Заявка на персонал'],
            ['equipment', 'Заявка на технику'],
            ['info', 'Запрос информации'],
            ['issue', 'Сообщение о проблеме'],
            ['future_type', 'future_type'],
            ['', ''],
        ];
    }

    public function test_two_sheet_material_request_report_keeps_file_intent_and_organization_scope(): void
    {
        $query = 'Собери Excel для контроля заявок на материалы по всей организации. На первом листе — незакрытые заявки со сроком раньше сегодняшнего дня; на втором — незакрытые заявки без ответственного, в том числе без срока. Нужны идентификатор заявки, название, проект, статус, приоритет, требуемая дата и ответственный. Черновики, закрытые и отменённые заявки исключи, заявки на технику и персонал тоже. Если заявка подходит под оба условия, включи её на оба листа.';
        self::assertNull(AssistantMutationIntent::compoundType($query));
        self::assertFalse(AssistantMutationIntent::containsRequest($query));
        self::assertFalse(AssistantMutationIntent::containsFollowUpCommand($query));
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query);
        self::assertSame('generate_report', $understanding->primaryIntent);
        self::assertSame('xlsx', $understanding->outputFormat);
        self::assertSame('allow_file_generation', $understanding->actionPolicy);
        self::assertTrue(AssistantCustomReportIntent::matches($query, $understanding));
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 102]]]],
            'request_understanding' => $understanding->toArray()];
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
        self::assertContains('generate_custom_report', AssistantToolSurface::names(['generate_custom_report'], $plan));
    }

    #[DataProvider('spreadsheetPlacementRequests')]
    public function test_spreadsheet_row_placement_is_not_a_business_mutation(string $query, string $format): void
    {
        self::assertNull(AssistantMutationIntent::compoundType($query));
        self::assertFalse(AssistantMutationIntent::containsRequest($query));
        self::assertFalse(AssistantMutationIntent::containsFollowUpCommand($query));
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query);
        self::assertSame('generate_report', $understanding->primaryIntent);
        self::assertSame($format, $understanding->outputFormat);
    }

    public static function spreadsheetPlacementRequests(): array
    {
        return [
            ['Сделай Excel по заявкам: статус, дата. Включи её на оба листа.', 'xlsx'],
            ['Сделай XLSX по договорам: статус, сумма. Включите их в оба листа.', 'xlsx'],
            ['Собери Excel по заявкам с датой. Включи его на все листы.', 'xlsx'],
            ['Сделай Excel по заявкам: статус. Если заявка подходит, включи её на обоих листах.', 'xlsx'],
            ['Сделай Excel по заявкам со статусом. Включи её в каждом листе.', 'xlsx'],
            ['Сделай XLSX по заявкам: статус. Включи их во все листы.', 'xlsx'],
            ['Собери Excel со статусом заявок. Включи их в каждый из листов.', 'xlsx'],
            ['Собери Excel со статусом заявок. Включи её на всех листах.', 'xlsx'],
        ];
    }

    #[DataProvider('businessMutationRequests')]
    public function test_real_business_mutations_remain_visible(string $query): void
    {
        self::assertSame('update', AssistantMutationIntent::compoundType($query));
        self::assertTrue(AssistantMutationIntent::containsRequest($query));
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query);
        self::assertSame('update', $understanding->primaryIntent);
        self::assertSame('requires_confirmation', $understanding->actionPolicy);
    }

    public static function businessMutationRequests(): array
    {
        return [
            ['Сделай Excel по заявкам. Измени статус заявки на одобрена.'],
            ['Сделай Excel по заявкам. Включи уведомления о просрочке.'],
            ['Сделай Excel со статусом заявок. Включи её на оба листа. Назначь ответственного по заявке.'],
            ['Сделай Excel и покажи настройку уведомлений. Включи её.'],
        ];
    }
}
