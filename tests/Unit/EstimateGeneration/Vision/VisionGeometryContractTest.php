<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionElementData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisionGeometryContractTest extends TestCase
{
    #[Test]
    public function two_point_geometry_is_only_a_distinct_nonzero_polyline_for_non_area_types(): void
    {
        foreach (['dimension', 'axis', 'engineering_element', 'text'] as $type) {
            self::assertSame($type, (new VisionElementData('item-'.$type, $type, null, [[0.1, 0.1], [0.9, 0.9]], 0.8, 'page-1'))->type);
        }
        foreach (['room', 'wall', 'opening'] as $type) {
            $this->assertInvalid($type, [[0.1, 0.1], [0.9, 0.9]]);
        }
        $this->assertInvalid('axis', [[0.1, 0.1], [0.1, 0.1]]);
    }

    #[Test]
    public function rings_require_nonzero_area_and_simple_geometry(): void
    {
        $this->assertInvalid('room', [[0.1, 0.1], [0.5, 0.5], [0.9, 0.9]]);
        $this->assertInvalid('room', [[0.1, 0.1], [0.9, 0.9], [0.9, 0.1], [0.1, 0.9]]);
        self::assertCount(3, (new VisionElementData('room-valid', 'room', null, [[0.1, 0.1], [0.9, 0.1], [0.1, 0.9]], 0.8, 'page-1'))->polygon);
    }

    /** @param array<int, array{0: float, 1: float}> $geometry */
    private function assertInvalid(string $type, array $geometry): void
    {
        try {
            new VisionElementData('item-invalid', $type, null, $geometry, 0.8, 'page-1');
            self::fail('Invalid geometry was accepted.');
        } catch (VisionContractException) {
            self::assertTrue(true);
        }
    }
}
