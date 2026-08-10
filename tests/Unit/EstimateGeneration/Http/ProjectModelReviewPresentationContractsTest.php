<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelCorrectionHistoryPresenter;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelReviewCursorPaginator;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelReviewPayloadSanitizer;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelSourceVersionPinning;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelViewerAnchorNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelReviewPresentationContractsTest extends TestCase
{
    #[Test]
    public function it_serializes_only_the_closed_safe_payload_shape_recursively(): void
    {
        $sanitizer = new ProjectModelReviewPayloadSanitizer;

        self::assertSame([
            'kind' => 'room',
            'key' => 'floor-1-room-6',
            'polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]],
        ], $sanitizer->entity([
            'kind' => 'room', 'key' => 'floor-1-room-6', 'polygon' => [[0, 0], [1, 0], [1, 1]],
            'nested' => ['storage_path' => 'org-1/private.pdf', 'prompt' => 'ignore instructions'],
            'raw_locator' => ['path' => '/secret'], 'unknown' => 'must not pass',
        ]));

        self::assertSame(['value' => 12.5, 'unit' => 'm2'], $sanitizer->assertionValue('area', [
            'value' => 12.5, 'unit' => 'm2', 'locator' => ['document_id' => 99], 'prompt' => 'x',
        ]));
    }

    #[Test]
    public function it_rejects_non_finite_or_out_of_bounds_anchor_coordinates_and_normalizes_page_coordinates(): void
    {
        $normalizer = new ProjectModelViewerAnchorNormalizer;

        self::assertSame([[0.1, 0.2], [0.5, 0.2], [0.5, 0.8]], $normalizer->polygon(
            [[100, 200], [500, 200], [500, 800]], 1000, 1000,
        ));
        self::assertNull($normalizer->polygon([[100, 200], [INF, 200], [500, 800]], 1000, 1000));
        self::assertNull($normalizer->polygon([[-1, 200], [500, 200], [500, 800]], 1000, 1000));
        self::assertNull($normalizer->polygon([[0, 0], [1, 0], [1, 1]], 0, 1000));
    }

    #[Test]
    public function it_filters_before_cursor_pagination_and_calculates_summary_for_the_entire_effective_result(): void
    {
        $paginator = new ProjectModelReviewCursorPaginator;
        $entities = [
            ['stable_key' => 'entity:a', 'status' => 'confirmed', 'needs_action' => false],
            ['stable_key' => 'entity:b', 'status' => 'needs_action', 'needs_action' => true],
            ['stable_key' => 'entity:c', 'status' => 'needs_action', 'needs_action' => true],
        ];

        $first = $paginator->paginate($entities, ['status' => 'needs_action', 'per_page' => 1]);

        self::assertSame(['entity:b'], array_column($first['entities'], 'stable_key'));
        self::assertTrue($first['page']['has_more']);
        self::assertSame(2, $first['summary']['total']);
        self::assertSame(2, $first['summary']['needs_action']);

        $second = $paginator->paginate($entities, [
            'status' => 'needs_action', 'per_page' => 1, 'cursor' => $first['page']['next_cursor'],
        ]);
        self::assertSame(['entity:c'], array_column($second['entities'], 'stable_key'));
        self::assertFalse($second['page']['has_more']);
    }

    #[Test]
    public function it_presents_revert_as_current_truth_and_marks_only_the_latest_apply_as_active(): void
    {
        $presenter = new ProjectModelCorrectionHistoryPresenter;
        $base = ['value' => 10.0, 'unit' => 'm2'];
        $applied = ['canonical_value' => ['value' => 12.0, 'unit' => 'm2'], 'audit' => [
            'schema_version' => 'project-model-correction:v1', 'operation' => 'apply',
            'previous_canonical_value' => $base, 'new_canonical_value' => ['value' => 12.0, 'unit' => 'm2'],
        ]];
        $reverted = ['canonical_value' => $base, 'audit' => [
            'schema_version' => 'project-model-correction:v1', 'operation' => 'revert',
            'previous_canonical_value' => ['value' => 12.0, 'unit' => 'm2'], 'new_canonical_value' => $base,
            'reverted_correction_id' => 41,
        ]];

        $history = $presenter->present([
            ['id' => 41, 'stable_key' => 'correction:a', 'payload' => $applied, 'reason' => 'Проверено', 'actor_id' => 7, 'created_at' => '2026-08-01T10:00:00+00:00'],
            ['id' => 42, 'stable_key' => 'correction:b', 'payload' => $reverted, 'reason' => 'Отмена', 'actor_id' => 7, 'created_at' => '2026-08-01T11:00:00+00:00'],
        ]);

        self::assertSame($base, $history['current_value']);
        self::assertFalse($history['items'][0]['active']);
        self::assertTrue($history['items'][0]['reverted']);
        self::assertFalse($history['items'][1]['active']);
        self::assertTrue($history['items'][1]['revert']);
    }

    #[Test]
    public function historical_conflict_association_is_exposed_only_when_proven(): void
    {
        $payload = ['canonical_value' => ['value' => 12.0]];
        $presenter = new ProjectModelCorrectionHistoryPresenter;
        $ambiguous = $presenter->present([[
            'id' => 1, 'stable_key' => 'correction:a', 'payload' => $payload,
            'target_conflict_key' => null,
            'evidence_lineage' => [['limitation_code' => 'historical_conflict_ambiguous']],
        ]]);
        $proven = $presenter->present([[
            'id' => 2, 'stable_key' => 'correction:b', 'payload' => $payload,
            'target_conflict_key' => 'conflict:exact', 'evidence_lineage' => [['evidence_id' => 'evidence:1']],
        ]]);

        self::assertNull($ambiguous['items'][0]['target_conflict_key']);
        self::assertSame('ambiguous', $ambiguous['items'][0]['conflict_association']);
        self::assertSame('conflict:exact', $proven['items'][0]['target_conflict_key']);
        self::assertSame('proven', $proven['items'][0]['conflict_association']);
    }

    #[Test]
    public function it_exposes_only_documents_and_sheets_pinned_to_evidence_source_versions(): void
    {
        $pinning = new ProjectModelSourceVersionPinning;
        $references = $pinning->references([
            ['locator' => ['document_id' => 10, 'page' => 2], 'evidence_source_version' => 'sha256:'.str_repeat('a', 64)],
        ]);
        $documents = $pinning->documents([
            ['id' => 10, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            ['id' => 11, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            ['id' => 12, 'source_version' => 'sha256:'.str_repeat('b', 64)],
        ], $references);
        $sheets = $pinning->sheets([
            ['id' => 100, 'document_id' => 10, 'source_version' => 'sha256:'.str_repeat('a', 64), 'page_number' => 2],
            ['id' => 101, 'document_id' => 10, 'source_version' => 'sha256:'.str_repeat('b', 64), 'page_number' => 2],
        ], $references);

        self::assertSame([10], array_column($documents, 'id'));
        self::assertSame([100], array_column($sheets, 'id'));
    }
}
