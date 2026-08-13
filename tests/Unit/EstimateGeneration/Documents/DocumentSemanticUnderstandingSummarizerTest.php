<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSemanticUnderstandingSummarizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentSemanticUnderstandingSummarizerTest extends TestCase
{
    #[Test]
    public function it_merges_bounded_semantic_facts_questions_and_cross_page_entities(): void
    {
        $payload = static fn (int $page, string $role, array $facts): array => [
            'page_number' => $page,
            'vision_analysis' => ['project_sheet_analysis' => [
                'role' => $role,
                'facts' => $facts,
            ]],
            'semantic_quality' => [
                'checked' => ['material', 'opening'],
                'found' => ['opening'],
                'missing' => ['material'],
                'needs_targeted' => ['material'],
                'quarantined_items' => [['section' => 'elements', 'reason' => 'invalid_element']],
            ],
        ];
        $fact = static fn (string $key, string $type): array => [
            'entityKey' => $key,
            'factType' => $type,
            'value' => ['type' => 'unknown', 'data' => null],
        ];

        $result = (new DocumentSemanticUnderstandingSummarizer)->summarize([
            $payload(11, 'facade', [$fact('opening-a', 'opening'), $fact('material-a', 'unresolved_question')]),
            $payload(17, 'specification', [$fact('opening-a', 'material'), $fact('material-a', 'recommendation')]),
        ]);

        self::assertSame(2, $result['pages_checked']);
        self::assertSame(['facade' => 1, 'specification' => 1], $result['roles']);
        self::assertCount(4, $result['facts']);
        self::assertCount(1, $result['questions']);
        self::assertCount(1, $result['recommendations']);
        self::assertSame([
            ['entity_key' => 'material-a', 'pages' => [11, 17]],
            ['entity_key' => 'opening-a', 'pages' => [11, 17]],
        ], $result['cross_page_connections']);
        self::assertSame(2, $result['quarantined_count']);
        self::assertFalse($result['truncated']);
    }

    public function test_reports_truncation_for_each_bounded_group(): void
    {
        $questions = array_map(static fn (int $index): array => [
            'entityKey' => 'question-'.$index,
            'factType' => 'unresolved_question',
            'value' => ['type' => 'string', 'data' => 'Question '.$index],
        ], range(1, 129));

        $summary = (new DocumentSemanticUnderstandingSummarizer)->summarize([[
            'page_number' => 1,
            'vision_analysis' => [
                'project_sheet_analysis' => ['role' => 'plan', 'facts' => $questions],
            ],
            'semantic_quality' => [],
        ]]);

        self::assertCount(128, $summary['questions']);
        self::assertTrue($summary['truncated']);
    }
}
