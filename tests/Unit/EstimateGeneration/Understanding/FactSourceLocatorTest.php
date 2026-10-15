<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator;
use PHPUnit\Framework\TestCase;

final class FactSourceLocatorTest extends TestCase
{
    public function test_exact_source_shapes_are_closed_bounded_and_keep_original_coordinates(): void
    {
        $polygon = ['kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1',
            'value' => [[0.1, 0.1], [0.4, 0.1], [0.4, 0.2], [0.1, 0.2]]];
        self::assertSame($polygon, (new FactSourceLocator($polygon))->value);
        self::assertEqualsWithDelta(['x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.1], (new FactSourceLocator($polygon))->region(), 0.000001);
        foreach ([[[0.1, 0.5], [0.9, 0.5]], [[0.5, 0.1], [0.5, 0.9]]] as $line) {
            self::assertNull((new FactSourceLocator([...$polygon, 'value' => $line]))->region());
        }
        self::assertTrue(FactSourceLocator::valid(['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'pdf:page:1/text']));
        foreach ([null, [], [...$polygon, 'kind' => null], [...$polygon, 'extra' => true],
            [...$polygon, 'coordinate_space' => 'normalized_derivative_v1'], [...$polygon, 'value' => [[0, 0], [0, 0]]],
            [...$polygon, 'value' => [[0, 0], [2, 1]]], [...$polygon, 'value' => [[0, 0], ['1', 1]]],
            ['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'https://example.com/file'],
            ['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'pdf:page:1/\\secret']] as $invalid) {
            self::assertFalse(FactSourceLocator::valid($invalid));
        }
    }
}
