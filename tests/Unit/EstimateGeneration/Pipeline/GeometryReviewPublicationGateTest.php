<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPublicationGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryReviewPublicationGateTest extends TestCase
{
    #[Test]
    public function canonical_geometry_review_item_blocks_writer_and_is_human_readable(): void
    {
        $draft = (new BuildMostEstimateDraft(static fn (string $key): string => match ($key) {
            'estimate_generation.geometry_coverage_review' => 'Подтвердите полноту геометрии и наличие проёмов для указанного элемента.',
            default => $key,
        }))->build([
            'catalog_identity' => ['status' => 'current'],
            'technology_identity' => ['status' => 'current'],
            'rule_identity' => ['status' => 'current'],
            'local_estimates' => [],
            'stage6_review_items' => [[
                'type' => 'quantity_blocking',
                'code' => 'geometry_coverage_unknown',
                'message_key' => 'estimate_generation.geometry_coverage_review',
                'entity_id' => 'wall:1',
                'source_refs' => ['artifact:plan:page:1'],
            ]],
        ]);

        self::assertFalse($draft['is_complete']);
        self::assertSame('review_required', $draft['stage6_status']);
        self::assertSame(
            'Подтвердите полноту геометрии и наличие проёмов для указанного элемента.',
            $draft['stage6_review_items'][0]['message'],
        );
        $writes = 0;
        self::assertFalse((new DraftPublicationGate)->persistWhenAllowed($draft, false, static function () use (&$writes): void {
            $writes++;
        }));
        self::assertSame(0, $writes);
    }
}
