<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConversionResult;
use RuntimeException;
use Tests\TestCase;

final class DesignViewerConversionResultTest extends TestCase
{
    public function test_accepts_renderable_fragment_metrics(): void
    {
        $result = DesignViewerConversionResult::fromPayload([
            'metrics' => [
                'format' => 'thatopen_frag',
                'raw' => false,
                'local_id_count' => 24,
                'category_count' => 8,
                'sample_count' => 40,
                'representation_count' => 12,
                'shell_count' => 12,
                'bounding_box' => [
                    'min' => ['x' => -12.5, 'y' => 0.0, 'z' => -1.25],
                    'max' => ['x' => 18.75, 'y' => 9.5, 'z' => 4.25],
                ],
            ],
            'warnings' => ['large_coordinates_normalized'],
        ]);

        $result->assertRenderableGeometry();

        $metadata = $result->metadata();

        $this->assertSame('thatopen_frag', $metadata['format']);
        $this->assertSame(24, $metadata['geometry']['local_id_count']);
        $this->assertSame(40, $metadata['geometry']['sample_count']);
        $this->assertSame(12, $metadata['geometry']['representation_count']);
        $this->assertSame(12, $metadata['geometry']['shell_count']);
        $this->assertSame(
            ['x' => -12.5, 'y' => 0.0, 'z' => -1.25],
            $metadata['geometry']['bounding_box']['min']
        );
        $this->assertSame(['large_coordinates_normalized'], $metadata['warnings']);
    }

    public function test_rejects_fragment_without_renderable_geometry(): void
    {
        $result = DesignViewerConversionResult::fromPayload([
            'metrics' => [
                'format' => 'thatopen_frag',
                'local_id_count' => 0,
                'sample_count' => 0,
                'representation_count' => 0,
                'shell_count' => 0,
                'bounding_box' => null,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prepared viewer file does not contain renderable BIM geometry.');

        $result->assertRenderableGeometry();
    }

    public function test_rejects_fragment_with_degenerate_bounding_box(): void
    {
        $result = DesignViewerConversionResult::fromPayload([
            'metrics' => [
                'format' => 'thatopen_frag',
                'local_id_count' => 3,
                'sample_count' => 3,
                'representation_count' => 2,
                'shell_count' => 2,
                'bounding_box' => [
                    'min' => ['x' => 1.0, 'y' => 1.0, 'z' => 1.0],
                    'max' => ['x' => 1.0, 'y' => 1.0, 'z' => 1.0],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prepared viewer file has an invalid BIM bounding box.');

        $result->assertRenderableGeometry();
    }
}
