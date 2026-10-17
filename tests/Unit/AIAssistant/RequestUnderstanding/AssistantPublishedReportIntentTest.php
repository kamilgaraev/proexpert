<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantPublishedReportIntentTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_accepted_production_uses_published_report_in_requested_format(): void
    {
        foreach (['Excel' => 'xlsx', 'CSV' => 'csv', 'PDF' => 'pdf'] as $word => $format) {
            $query = 'Сформируй '.$word.' отчёт о принятой выработке текущего проекта за прошлую неделю';
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, []);
            self::assertTrue(AssistantPublishedReportIntent::matches($query, $understanding));
            $tools = AssistantToolSurface::names(['generate_published_report', 'describe_published_reports', 'generate_custom_report', 'generate_work_completion_report'],
                ['request' => ['message' => $query], 'request_understanding' => $understanding->toArray()]);
            self::assertContains('generate_published_report', $tools);
            self::assertContains('describe_published_reports', $tools);
            self::assertNotContains('generate_work_completion_report', $tools);
            self::assertNotContains('generate_custom_report', $tools);
            $policy = new AssistantToolEligibilityPolicy;
            self::assertTrue($policy->canExecuteTool('generate_published_report', $understanding, false, ['format' => $format])->allowed);
            self::assertFalse($policy->canExecuteTool('generate_published_report', $understanding, false, ['format' => $format === 'xlsx' ? 'csv' : 'xlsx'])->allowed);
        }
    }

    public function test_ordinary_multisheet_report_keeps_custom_generator(): void
    {
        $query = 'Сделай Excel по договорам всей организации: на втором листе итоги по статусам';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, []);
        self::assertFalse(AssistantPublishedReportIntent::matches($query, $understanding));
        $tools = AssistantToolSurface::names(['generate_custom_report', 'describe_custom_report_source', 'generate_published_report', 'describe_published_reports'],
            ['request' => ['message' => $query], 'request_understanding' => $understanding->toArray()]);
        self::assertContains('generate_custom_report', $tools);
        self::assertContains('describe_custom_report_source', $tools);
    }

    public function test_accepted_works_and_volumes_do_not_fall_back_to_completed_work(): void
    {
        foreach (['Выгрузи Excel по принятым работам текущего проекта', 'Сделай CSV с принятыми объёмами по проекту'] as $query) {
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, []);
            self::assertTrue(AssistantPublishedReportIntent::matches($query, $understanding));
        }
    }

    public function test_explanation_without_file_and_recurring_subscription_do_not_generate_once(): void
    {
        foreach (['Расскажи о принятой выработке без файла', 'Создай подписку на отчёт о принятой выработке каждую пятницу'] as $query) {
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, []);
            self::assertFalse(AssistantPublishedReportIntent::matches($query, $understanding));
            self::assertFalse((new AssistantToolEligibilityPolicy)->canExposeTool('generate_published_report', $understanding)->allowed);
        }
    }
}
