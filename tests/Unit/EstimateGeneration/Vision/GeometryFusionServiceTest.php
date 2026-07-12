<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\FusedGeometryElementData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryFusionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryFusionServiceTest extends TestCase
{
    #[Test]
    public function fusion_is_order_independent_and_deduplicates_identical_evidence(): void
    {
        $a = self::element('room-1', 'e1', [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]);
        $service = new GeometryFusionService;

        self::assertSame($service->fuse([$a, $a])->toArray(), $service->fuse([$a, $a])->toArray());
        self::assertCount(1, $service->fuse([$a, $a])->elements);
    }

    #[Test]
    public function same_key_with_different_geometry_becomes_blocking_conflict(): void
    {
        $result = (new GeometryFusionService)->fuse([
            self::element('room-1', 'e1', [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]),
            self::element('room-1', 'e2', [[0.0, 0.0], [2.0, 0.0], [2.0, 1.0]]),
        ]);

        self::assertSame('geometry_element_conflict', $result->issues[0]['code']);
        self::assertSame(['e1', 'e2'], $result->issues[0]['evidence_refs']);
    }

    private static function element(string $key, string $evidence, array $geometry): FusedGeometryElementData
    {
        return new FusedGeometryElementData($key, 'room', $geometry, 'vision', $evidence, 'sha256:'.str_repeat('b', 64), 1, 'normalized_source_v1', 'runtime:v1', 'model:v1', 0.9);
    }
}
