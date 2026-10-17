<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantPublishedReportProjectScopeTest extends TestCase
{
    public static function scopeRequests(): array
    {
        return [
            'named_project_differs_from_ui' => ['Сформируй Excel по проекту «Учебный ПТО»', 52, 102, true],
            'named_object_differs_from_ui' => ['Сформируй Excel по объекту СевГУ', 52, 102, true],
            'named_project_without_ui' => ['Сформируй Excel по проекту «Учебный ПТО»', null, 102, true],
            'named_project_cannot_expand_to_organization' => ['Сформируй Excel о состоянии проекта «Учебный ПТО»', 52, null, false],
            'named_object_cannot_expand_to_organization' => ['Сформируй Excel по объекту СевГУ', 52, null, false],
            'project_list_allows_organization_scope' => ['Сформируй Excel со списком проектов', 52, null, true],
            'all_projects_ignore_ui' => ['Сформируй Excel по всем проектам', 52, null, true],
            'all_projects_cannot_narrow_to_ui' => ['Сформируй Excel по всем проектам', 52, 52, false],
            'current_project_uses_ui' => ['Сформируй Excel текущего проекта', 52, 52, true],
            'current_project_cannot_change' => ['Сформируй Excel текущего проекта', 52, 102, false],
            'current_project_requires_ui' => ['Сформируй Excel текущего проекта', null, 102, false],
            'current_project_cannot_expand' => ['Сформируй Excel текущего проекта', 52, null, false],
            'organization_report_does_not_inherit_ui' => ['Сформируй Excel по всей организации', 52, null, true],
            'project_named_report_for_whole_organization' => ['Сформируй отчёт «Состояние проекта» по всей организации', 52, null, true],
        ];
    }

    #[DataProvider('scopeRequests')]
    public function test_report_scope_matches_the_user_request(string $message, ?int $selectedProjectId, ?int $reportProjectId, bool $allowed): void
    {
        $payload = ['message' => $message, 'context' => ['entity_refs' => $selectedProjectId === null ? []
            : [['entity_type' => 'project', 'entity_id' => $selectedProjectId]]]];

        self::assertSame($allowed, AssistantPublishedReportProjectScope::allows($payload, $reportProjectId));
    }

    public function test_quoted_project_preserves_its_full_name_and_excludes_following_report_requirements(): void
    {
        self::assertSame('Учебный ПТО — ремонт офиса 100 м² — 13.10.2026', AssistantReportEntityQuery::extract(
            'Подготовь Excel по проекту «Учебный ПТО — ремонт офиса 100 м² — 13.10.2026». На одном листе работы, на втором задачи.', 'project'));
        self::assertSame('Склад на улице Мира, этап 2', AssistantReportEntityQuery::extract(
            'Отчёт по объекту «Склад на улице Мира, этап 2» за октябрь', 'project'));
        self::assertSame('СевГУ', AssistantReportEntityQuery::extract('Отчёт по проекту СевГУ за октябрь', 'project'));
    }
}
