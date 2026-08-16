<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSemanticUnderstandingSummarizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentSemanticUnderstandingSummarizerTest extends TestCase
{
    #[Test]
    public function it_merges_bounded_semantic_facts_without_user_questions(): void
    {
        $payload = static fn (int $page, string $role, array $facts): array => [
            'page_number' => $page,
            'vision_analysis' => ['project_sheet_analysis' => ['role' => $role, 'facts' => $facts]],
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
        self::assertArrayNotHasKey('questions', $result);
        self::assertArrayNotHasKey('ai_question_count', $result);
        self::assertCount(1, $result['recommendations']);
        self::assertSame([
            ['entity_key' => 'material-a', 'pages' => [11, 17]],
            ['entity_key' => 'opening-a', 'pages' => [11, 17]],
        ], $result['cross_page_connections']);
        self::assertSame(2, $result['quarantined_count']);
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function legacy_geometry_questions_do_not_enter_the_document_summary(): void
    {
        $summary = (new DocumentSemanticUnderstandingSummarizer)->summarize([[
            'schema_version' => 4,
            'page_number' => 7,
            'role_completion' => [
                'observer_literal' => true,
                'observer_construction' => true,
                'observer_risk' => true,
                'arbiter' => true,
            ],
            'analysis_routing' => [
                'observer_roles' => ['observer_literal', 'observer_construction', 'observer_risk'],
                'arbiter_required' => true,
            ],
            'ai_questions' => [[
                'code' => 'partial_opening_geometry_abc123',
                'choices' => [],
            ]],
        ]]);

        self::assertArrayNotHasKey('ai_question_count', $summary);
        self::assertArrayNotHasKey('questions', $summary);
        self::assertTrue($summary['analysis_roles_complete']);
    }

    #[Test]
    public function completed_drawing_analysis_projects_quantity_capability_only_from_an_accepted_numeric_claim(): void
    {
        $summary = (new DocumentSemanticUnderstandingSummarizer)->summarize([[
            'schema_version' => 4,
            'page_number' => 4,
            'role_completion' => [
                'observer_literal' => true,
                'observer_construction' => true,
                'observer_risk' => true,
                'arbiter' => true,
            ],
            'analysis_routing' => [
                'observer_roles' => ['observer_literal', 'observer_construction', 'observer_risk'],
                'arbiter_required' => true,
            ],
            'vision_analysis' => ['sheet_type' => 'floor_plan'],
            'independent_observations' => ['observer_literal' => [
                'claims' => [[
                    'factType' => 'area',
                    'value' => ['type' => 'number', 'data' => 72.19],
                    'unit' => 'm2',
                    'evidenceRef' => 'page-4-literal',
                ], [
                    'factType' => 'area',
                    'value' => ['type' => 'string', 'data' => 'not-a-number'],
                    'unit' => 'm2',
                    'evidenceRef' => 'page-4-malformed',
                ]],
            ]],
            'document_arbitration' => [
                'decisions' => [[
                    'claim_id' => 'literal:1',
                    'status' => 'accepted',
                    'evidence_refs' => ['page-4-literal'],
                ], [
                    'claim_id' => 'literal:2',
                    'status' => 'accepted',
                    'evidence_refs' => ['page-4-malformed'],
                ]],
            ],
        ]]);

        self::assertSame('drawing_analysis', $summary['document_understanding']['role_for_estimation']);
        self::assertTrue($summary['document_understanding']['extracted_capabilities']['has_quantities']);
        self::assertFalse($summary['document_understanding']['extracted_capabilities']['requires_manual_review']);
        self::assertSame(1, $summary['document_understanding']['extracted_capabilities']['accepted_quantity_claims']);
    }

    #[Test]
    public function incomplete_or_unaccepted_drawing_analysis_cannot_promote_quantity_evidence(): void
    {
        $summary = (new DocumentSemanticUnderstandingSummarizer)->summarize([[
            'schema_version' => 4,
            'page_number' => 4,
            'role_completion' => [
                'observer_literal' => true,
                'arbiter' => false,
            ],
            'analysis_routing' => [
                'observer_roles' => ['observer_literal'],
                'arbiter_required' => true,
            ],
            'vision_analysis' => ['sheet_type' => 'floor_plan'],
            'independent_observations' => ['observer_literal' => [
                'claims' => [[
                    'factType' => 'area',
                    'value' => ['type' => 'number', 'data' => 72.19],
                    'unit' => 'm2',
                    'evidenceRef' => 'page-4-literal',
                ]],
            ]],
            'document_arbitration' => [
                'decisions' => [[
                    'claim_id' => 'literal:1',
                    'status' => 'unresolved',
                    'evidence_refs' => ['page-4-literal'],
                ]],
            ],
        ]]);

        self::assertSame('needs_review', $summary['document_understanding']['role_for_estimation']);
        self::assertFalse($summary['document_understanding']['extracted_capabilities']['has_quantities']);
        self::assertTrue($summary['document_understanding']['extracted_capabilities']['requires_manual_review']);
        self::assertSame(0, $summary['document_understanding']['extracted_capabilities']['accepted_quantity_claims']);
    }
}
