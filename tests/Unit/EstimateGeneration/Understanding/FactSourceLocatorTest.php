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
        foreach ([[[0.1, 0.5], [0.9, 0.5]], [[0.5, 0.1], [0.5, 0.9]],
            [[0.1, 0.1], [0.9, 0.9]], [[0.1, 0.1], [0.5, 0.5], [0.9, 0.9]]] as $line) {
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

    public function test_every_evidence_consumer_reads_the_typed_source_without_internal_fallback_markers(): void
    {
        $native = new FactSourceLocator(['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'pdf:page:1/text']);
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('proof:1', 1, 2, 3,
            'sha256:'.str_repeat('a', 64), 'document:42', 'document', 1,
            ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'evidence-node:1', factSource: $native);
        self::assertSame('pdf:page:1/text', $proof->nativeReference);
        self::assertNull($proof->region);
        $area = new FactSourceLocator(['kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1',
            'value' => [[0.1, 0.2], [0.4, 0.2], [0.4, 0.6], [0.1, 0.6]]]);
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('proof:2', 1, 2, 3,
            'sha256:'.str_repeat('a', 64), 'document:42', 'document', 1, null, 'element:legacy', factSource: $area);
        self::assertNull($proof->nativeReference);
        self::assertEqualsWithDelta($area->region(), $proof->region, 0.000001);
    }
}
