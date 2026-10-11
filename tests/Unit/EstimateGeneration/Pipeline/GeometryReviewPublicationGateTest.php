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
    public function operand_scope_allows_only_the_current_source_manifest_and_never_another_tenant(): void
    {
        $method = new \ReflectionMethod(BuildMostEstimateDraft::class, 'operandsMatchScope');
        $scope = ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'source_version' => 'source:first',
            'source_versions' => ['source:first', 'source:second']];
        $operand = ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'source_version' => 'source:second'];
        self::assertTrue($method->invoke(new BuildMostEstimateDraft, [$operand], $scope));
        self::assertFalse($method->invoke(new BuildMostEstimateDraft, [[...$operand, 'source_version' => 'source:undeclared']], $scope));
        self::assertFalse($method->invoke(new BuildMostEstimateDraft, [[...$operand, 'organization_id' => 4]], $scope));
        unset($scope['source_versions']);
        self::assertFalse($method->invoke(new BuildMostEstimateDraft, [$operand], $scope));
    }

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
