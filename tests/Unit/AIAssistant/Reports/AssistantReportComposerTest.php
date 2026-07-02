<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSourceRetrieverInterface;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AssistantReportComposerTest extends TestCase
{
    public function test_sources_create_grounded_report_draft(): void
    {
        $composer = new AssistantReportComposer(
            new FakeAssistantReportSourceRetriever([
                new RagSearchResult(
                    sourceType: 'project',
                    entityType: 'project',
                    entityId: '88',
                    projectId: 88,
                    title: 'Паспорт проекта Кирпичный дом Лесной двор',
                    excerpt: 'Проект Кирпичный дом Лесной двор находится в работе. Есть риск задержки поставки кирпича.',
                    similarity: 0.91,
                    metadata: ['status' => 'active'],
                    updatedAt: new DateTimeImmutable('2026-06-01T10:00:00+03:00')
                ),
                new RagSearchResult(
                    sourceType: 'contract',
                    entityType: 'contract',
                    entityId: '501',
                    projectId: 88,
                    title: 'Договор подряда по проекту',
                    excerpt: 'Договор подтверждает строительные работы по объекту и контроль сроков подрядчика.',
                    similarity: 0.86,
                    metadata: [],
                    updatedAt: null
                ),
            ]),
            new RagPromptContextBuilder
        );

        $report = $composer->compose($this->organization(), $this->user(), [
            'report_type' => 'project_rag',
            'project_id' => 88,
            'query' => 'PDF-отчет по проекту Кирпичный дом Лесной двор',
        ]);

        $this->assertTrue($report['has_sufficient_data']);
        $this->assertSame('Отчет по проекту Кирпичный дом Лесной двор', $report['title']);
        $this->assertSame('по проекту Кирпичный дом Лесной двор', $report['topic']);
        $this->assertStringContainsString('Кирпичный дом Лесной двор', $report['title']);
        $this->assertStringContainsString('Кирпичный дом Лесной двор', $report['summary']);
        $this->assertNotSame([], $report['sections']);
        $this->assertSame('Паспорт проекта Кирпичный дом Лесной двор', $report['sections'][0]['source_title']);
        $this->assertStringContainsString('Проект Кирпичный дом Лесной двор находится в работе', $report['sections'][0]['fact']);
        $this->assertContains('Тип: Проект', $report['sections'][0]['meta']);
        $this->assertContains('Дата: 01.06.2026', $report['sections'][0]['meta']);
        $this->assertContains('Релевантность: 91%', $report['sections'][0]['meta']);
        $this->assertNotSame([], $report['key_findings']);
        $this->assertStringContainsString('риск задержки', $report['risks'][0]);
        $this->assertNotSame([], $report['next_actions']);
        $this->assertSame('Паспорт проекта Кирпичный дом Лесной двор', $report['sources'][0]['title']);
        $this->assertSame('Паспорт проекта Кирпичный дом Лесной двор', $report['sources'][0]['display_title']);
        $this->assertSame('91%', $report['sources'][0]['score_label']);
        $this->assertSame(88, $report['sources'][0]['project_id']);
        $this->assertSame([], $report['limitations']);
    }

    public function test_verbose_pdf_query_is_normalized_into_short_management_title(): void
    {
        $composer = new AssistantReportComposer(
            new FakeAssistantReportSourceRetriever([
                new RagSearchResult(
                    sourceType: 'purchase_request',
                    entityType: 'purchase_request',
                    entityId: '901',
                    projectId: 88,
                    title: 'Заявка на закупку кирпича',
                    excerpt: str_repeat('Есть риск задержки поставки кирпича из-за согласования лимита. ', 12),
                    similarity: 0.78,
                    metadata: [],
                    updatedAt: new DateTimeImmutable('2026-06-03T12:00:00+03:00')
                ),
            ]),
            new RagPromptContextBuilder
        );

        $report = $composer->compose($this->organization(), $this->user(), [
            'report_type' => 'generic_rag',
            'query' => 'Пожалуйста, сформируй PDF-отчет по теме рисков снабжения по проекту Кирпичный дом Лесной двор с максимально подробным описанием всех найденных документов и ссылок',
        ]);

        $this->assertStringStartsWith('Отчет: Рисков снабжения', $report['title']);
        $this->assertStringNotContainsString('PDF', $report['title']);
        $this->assertStringNotContainsString('сформируй', mb_strtolower($report['title']));
        $this->assertLessThanOrEqual(93, mb_strlen($report['title']));
        $this->assertLessThanOrEqual(423, mb_strlen($report['sections'][0]['fact']));
        $this->assertLessThanOrEqual(183, mb_strlen($report['sources'][0]['reference_excerpt']));
        $this->assertSame('Заявка на закупку', $report['sources'][0]['type_label']);
    }

    public function test_rag_source_text_is_normalized_for_management_pdf(): void
    {
        $composer = new AssistantReportComposer(
            new FakeAssistantReportSourceRetriever([
                new RagSearchResult(
                    sourceType: 'supplier_request',
                    entityType: 'supplier_request',
                    entityId: '303',
                    projectId: 88,
                    title: 'Запрос поставщику ЗП-ГП-ЛД-003',
                    excerpt: 'Запрос поставщику: ЗП-ГП-ЛД-003 Проект: Кирпичный дом "Лесной двор" Status: responded Отправлен: 2026-06-10 Открыт: 2026-06-11 Ответ получен: 2026-06-11',
                    similarity: 0.89,
                    metadata: [],
                    updatedAt: new DateTimeImmutable('2026-06-11T09:00:00+03:00')
                ),
            ]),
            new RagPromptContextBuilder
        );

        $report = $composer->compose($this->organization(), $this->user(), [
            'report_type' => 'generic_rag',
            'query' => 'Сделай отчет по закупкам для Лесного двора',
        ]);

        $fact = (string) $report['sections'][0]['fact'];

        $this->assertStringContainsString('Статус: Ответ получен', $fact);
        $this->assertStringContainsString('Отправлен: 10.06.2026', $fact);
        $this->assertStringContainsString('Открыт: 11.06.2026', $fact);
        $this->assertStringNotContainsString('Status: responded', $fact);
        $this->assertStringNotContainsString('2026-06-10', $fact);
        $this->assertStringContainsString('Статус: Ответ получен', implode(' ', $report['key_findings']));
        $this->assertStringContainsString('10.06.2026', $report['sources'][0]['reference_excerpt']);
    }

    public function test_empty_sources_return_insufficient_data_report_without_file_content(): void
    {
        $composer = new AssistantReportComposer(
            new FakeAssistantReportSourceRetriever([]),
            new RagPromptContextBuilder
        );

        $report = $composer->compose($this->organization(), $this->user(), [
            'report_type' => 'project_rag',
            'project_id' => 88,
            'query' => 'PDF-отчет по проекту Кирпичный дом Лесной двор',
        ]);

        $this->assertFalse($report['has_sufficient_data']);
        $this->assertStringContainsString('данных недостаточно', mb_strtolower($report['summary']));
        $this->assertSame('Отчет по проекту Кирпичный дом Лесной двор', $report['title']);
        $this->assertSame([], $report['key_findings']);
        $this->assertSame([], $report['sections']);
        $this->assertSame([], $report['risks']);
        $this->assertSame([], $report['next_actions']);
        $this->assertSame([], $report['sources']);
        $this->assertNotSame([], $report['limitations']);
    }

    private function organization(): Organization
    {
        $organization = new Organization;
        $organization->id = 72;
        $organization->name = 'ПроХелпер';

        return $organization;
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 7;
        $user->name = 'Инженер';

        return $user;
    }
}

final readonly class FakeAssistantReportSourceRetriever implements AssistantReportSourceRetrieverInterface
{
    /**
     * @param  array<int, RagSearchResult>  $results
     */
    public function __construct(
        private array $results
    ) {}

    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array
    {
        return $this->results;
    }
}
