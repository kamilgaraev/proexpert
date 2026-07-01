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
        $this->assertStringContainsString('Кирпичный дом Лесной двор', $report['title']);
        $this->assertStringContainsString('Кирпичный дом Лесной двор', $report['summary']);
        $this->assertNotSame([], $report['sections']);
        $this->assertStringContainsString('риск задержки', $report['risks'][0]);
        $this->assertNotSame([], $report['next_actions']);
        $this->assertSame('Паспорт проекта Кирпичный дом Лесной двор', $report['sources'][0]['title']);
        $this->assertSame(88, $report['sources'][0]['project_id']);
        $this->assertSame([], $report['limitations']);
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
     * @param array<int, RagSearchResult> $results
     */
    public function __construct(
        private array $results
    ) {}

    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array
    {
        return $this->results;
    }
}
