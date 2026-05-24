<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use DateTimeImmutable;
use Tests\TestCase;

class RagPromptContextBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-assistant.rag.max_chunks', 8);
    }

    public function test_builds_prompt_and_metadata_from_results(): void
    {
        $builder = new RagPromptContextBuilder();
        $result = new RagSearchResult(
            sourceType: 'schedule',
            entityType: 'schedule',
            entityId: '56',
            projectId: 10,
            title: 'Schedule source',
            excerpt: 'Concrete evidence from schedule.',
            similarity: 0.84321,
            metadata: ['status' => 'active'],
            updatedAt: new DateTimeImmutable('2026-05-23T10:00:00+03:00')
        );

        $context = $builder->build('what is blocked', [$result]);

        $this->assertStringContainsString('ProHelper', $context['prompt']);
        $this->assertStringContainsString('Проблема — что не так — что сделать', $context['prompt']);
        $this->assertStringContainsString('есть признаки проблемы', $context['prompt']);
        $this->assertStringContainsString('[1] Schedule source: Concrete evidence from schedule.', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertTrue($context['metadata']['used']);
        $this->assertSame('what is blocked', $context['metadata']['query']);
        $this->assertSame(8, $context['metadata']['limits']['requested']);
        $this->assertSame(1, $context['metadata']['limits']['returned']);
        $this->assertSame('schedule', $context['metadata']['sources'][0]['source_type']);
        $this->assertSame(0.8432, $context['metadata']['sources'][0]['score']);
        $this->assertSame('2026-05-23T10:00:00+03:00', $context['metadata']['sources'][0]['updated_at']);
        $this->assertSame('/projects/10/schedules/56', $context['metadata']['sources'][0]['navigation_target']['route']);
        $this->assertSame('Schedule source', $context['metadata']['sources'][0]['navigation_target']['state']['assistant_source']['title']);
    }

    public function test_empty_results_mark_context_unused_without_sources(): void
    {
        $context = (new RagPromptContextBuilder())->build('missing context', []);

        $this->assertSame('', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([], $context['metadata']['sources']);
        $this->assertSame(0, $context['metadata']['limits']['returned']);
    }

    public function test_builds_navigation_targets_for_expanded_sources(): void
    {
        config()->set('ai-assistant.rag.max_chunks', 20);

        $cases = [
            ['estimate', '55', 10, [], '/projects/10/estimates/55'],
            ['estimate_template', '7', null, [], '/templates/library'],
            ['estimate_library_item', '8', null, [], '/libraries'],
            ['normative_rate', '9', null, [], '/catalogs/estimate-positions'],
            ['estimate_catalog_item', '11', null, [], '/catalogs/estimate-positions'],
            ['construction_journal_entry', '21', 10, ['journal_id' => 3], '/journals/3/entries/21'],
            ['performance_act', '31', 10, [], '/acts/31'],
            ['payment_document', '41', 10, [], '/payments/documents/41'],
            ['quality_defect', '51', 10, [], '/quality-control/defects/51'],
            ['executive_document_set', '61', 10, [], '/executive-documentation/sets/61'],
            ['executive_document', '71', 10, ['document_set_id' => 6], '/executive-documentation/sets/6'],
        ];

        $results = array_map(
            static fn (array $case): RagSearchResult => new RagSearchResult(
                sourceType: 'test',
                entityType: $case[0],
                entityId: $case[1],
                projectId: $case[2],
                title: "Source {$case[0]}",
                excerpt: 'Evidence',
                similarity: 0.8,
                metadata: $case[3],
                updatedAt: null
            ),
            $cases
        );

        $context = (new RagPromptContextBuilder())->build('open source', $results);

        foreach ($cases as $index => $case) {
            $this->assertSame($case[4], $context['metadata']['sources'][$index]['navigation_target']['route']);
        }
    }
}
