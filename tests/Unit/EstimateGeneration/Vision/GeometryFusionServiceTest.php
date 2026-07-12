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
        $a = self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]);
        $b = self::element('room-2', 'e2', ['polygon' => [[2.0, 0.0], [3.0, 0.0], [3.0, 1.0]]]);
        $service = new GeometryFusionService;

        self::assertSame($service->fuse([$a, $b])->toArray(), $service->fuse([$b, $a])->toArray());
        self::assertCount(2, $service->fuse([$a, $b])->elements);
    }

    #[Test]
    public function identical_geometry_retains_all_independent_provenance(): void
    {
        $first = self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]);
        $second = self::element('room-1', 'e2', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]);

        self::assertSame(['e1', 'e2'], (new GeometryFusionService)->fuse([$second, $first])->elements[0]->evidenceRefs());
    }

    #[Test]
    public function same_key_with_different_geometry_becomes_blocking_conflict(): void
    {
        $result = (new GeometryFusionService)->fuse([
            self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]),
            self::element('room-1', 'e2', ['polygon' => [[0.0, 0.0], [2.0, 0.0], [2.0, 1.0]]]),
        ]);

        self::assertSame('geometry_element_conflict', $result->issues[0]['code']);
        self::assertSame(['e1', 'e2'], $result->issues[0]['evidence_refs']);
        self::assertSame([], $result->elements);
        self::assertCount(2, $result->sourceElements);
    }

    #[Test]
    public function geometry_shape_is_type_specific_and_finite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [INF, 0.0], [1.0, 1.0]]]);
    }

    #[Test]
    public function three_variants_accumulate_all_evidence_for_every_permutation(): void
    {
        $a = self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]);
        $b = self::element('room-1', 'e2', ['polygon' => [[0.0, 0.0], [2.0, 0.0], [2.0, 1.0]]]);
        $c = self::element('room-1', 'e3', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]);
        $service = new GeometryFusionService;
        $expected = null;
        foreach ([[$a, $b, $c], [$a, $c, $b], [$b, $a, $c], [$b, $c, $a], [$c, $a, $b], [$c, $b, $a]] as $permutation) {
            $result = $service->fuse($permutation);
            self::assertSame(['e1', 'e2', 'e3'], $result->issues[0]['evidence_refs']);
            $expected ??= $result->toArray();
            self::assertSame($expected, $result->toArray());
        }
    }

    #[Test]
    public function same_evidence_identity_cannot_describe_different_geometry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GeometryFusionService)->fuse([
            self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]]]),
            self::element('room-1', 'e1', ['polygon' => [[0.0, 0.0], [2.0, 0.0], [2.0, 1.0]]]),
        ]);
    }

    private static function element(string $key, string $evidence, array $geometry): FusedGeometryElementData
    {
        return new FusedGeometryElementData($key, 'room', $geometry, 'vision', $evidence, 'sha256:'.str_repeat('b', 64), 1, 'normalized_source_v1', 'runtime:v1', 'model:v1', 0.9);
    }
}
