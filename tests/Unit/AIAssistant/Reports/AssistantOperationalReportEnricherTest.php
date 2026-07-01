<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportEnricher;
use PHPUnit\Framework\TestCase;

final class AssistantOperationalReportEnricherTest extends TestCase
{
    public function test_adds_rag_sources_and_limitations_when_structured_sections_are_empty(): void
    {
        $report = [
            'summary_cards' => [
                ['label' => 'Всего записей', 'value' => '0', 'hint' => 'по отчету'],
            ],
            'sections' => [
                ['title' => 'Проекты', 'total' => 0, 'rows' => []],
            ],
        ];

        $enriched = (new AssistantOperationalReportEnricher)->enrich($report, $this->ragReport());

        $this->assertSame('Паспорт проекта', $enriched['sources'][0]['title']);
        $this->assertSame('Есть риск задержки поставки кирпича.', $enriched['rag_report']['risks'][0]);
        $this->assertFalse($enriched['has_structured_data']);
        $this->assertSame('primary', $enriched['rag_context_mode']);
        $this->assertSame('Проект находится в работе.', $enriched['key_findings'][0]);
        $this->assertNotSame([], $enriched['limitations']);
        $this->assertStringContainsString('структурированных разделах', $enriched['limitations'][0]);
    }

    public function test_keeps_structured_data_and_adds_rag_sources_as_enrichment(): void
    {
        $report = [
            'summary_cards' => [
                ['label' => 'Всего записей', 'value' => '3', 'hint' => 'по отчету'],
            ],
            'sections' => [
                ['title' => 'Проекты', 'total' => 1, 'rows' => [['Кирпичный дом']]],
            ],
        ];

        $enriched = (new AssistantOperationalReportEnricher)->enrich($report, $this->ragReport());

        $this->assertSame(1, $enriched['sections'][0]['total']);
        $this->assertSame('Паспорт проекта', $enriched['sources'][0]['title']);
        $this->assertTrue($enriched['has_structured_data']);
        $this->assertSame('supporting', $enriched['rag_context_mode']);
        $this->assertSame([], $enriched['limitations']);
    }

    public function test_adds_insufficient_data_limitation_when_nothing_is_found(): void
    {
        $report = [
            'summary_cards' => [
                ['label' => 'Всего записей', 'value' => '0', 'hint' => 'по отчету'],
            ],
            'sections' => [],
        ];
        $ragReport = [
            'has_sufficient_data' => false,
            'sources' => [],
            'limitations' => ['По запросу не найдено релевантных источников.'],
        ];

        $enriched = (new AssistantOperationalReportEnricher)->enrich($report, $ragReport);

        $this->assertSame([], $enriched['sources']);
        $this->assertStringContainsString('не найдено релевантных источников', mb_strtolower(implode(' ', $enriched['limitations'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function ragReport(): array
    {
        return [
            'has_sufficient_data' => true,
            'summary' => 'По найденным источникам: проект в работе.',
            'key_findings' => ['Проект находится в работе.'],
            'sections' => [
                [
                    'title' => 'Паспорт проекта',
                    'source_title' => 'Паспорт проекта',
                    'fact' => 'Проект в работе.',
                    'items' => ['Проект в работе.'],
                    'meta' => ['Тип: Проект'],
                ],
            ],
            'risks' => ['Есть риск задержки поставки кирпича.'],
            'next_actions' => ['Проверить источник.'],
            'sources' => [
                ['title' => 'Паспорт проекта', 'project_id' => 88, 'excerpt' => 'Проект в работе.'],
            ],
            'limitations' => [],
        ];
    }
}
