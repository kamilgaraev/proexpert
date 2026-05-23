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

        config()->set('ai-assistant.rag.enabled', true);
        config()->set('ai-assistant.rag.max_chunks', 8);
    }

    public function test_builds_prompt_and_metadata_from_results(): void
    {
        $builder = new RagPromptContextBuilder();
        $result = new RagSearchResult(
            sourceType: 'schedule',
            entityType: 'project',
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
        $this->assertStringContainsString('[1] Schedule source: Concrete evidence from schedule.', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertTrue($context['metadata']['used']);
        $this->assertSame('what is blocked', $context['metadata']['query']);
        $this->assertSame(8, $context['metadata']['limits']['requested']);
        $this->assertSame(1, $context['metadata']['limits']['returned']);
        $this->assertSame('schedule', $context['metadata']['sources'][0]['source_type']);
        $this->assertSame(0.8432, $context['metadata']['sources'][0]['score']);
        $this->assertSame('2026-05-23T10:00:00+03:00', $context['metadata']['sources'][0]['updated_at']);
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
}
