<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use Tests\TestCase;

final class PdfGeometryExtractorTest extends TestCase
{
    public function test_geometry_extractor_normalizes_worker_pages(): void
    {
        $worker = new class extends PdfGeometryWorker {
            public function extract(string $content, ?string $filename = null): array
            {
                return [
                    'provider' => 'pymupdf',
                    'model' => 'geometry_v1',
                    'pages' => [[
                        'page_number' => 5,
                        'width' => 841.89,
                        'height' => 595.28,
                        'rotation' => 0,
                        'text_blocks' => [[
                            'text' => 'Плита сбора мусора',
                            'bbox' => [10, 20, 210, 44],
                            'block_no' => 0,
                            'block_type' => 0,
                        ]],
                        'vector_elements' => [
                            [
                                'kind' => 'line',
                                'bbox' => ['x' => 100, 'y' => 100, 'width' => 200, 'height' => 0],
                                'geometry' => ['points' => [[100, 100], [300, 100]]],
                            ],
                        ],
                        'visual_metrics' => [
                            'line_count' => 42,
                            'curve_count' => 0,
                            'rect_count' => 8,
                            'table_candidate_count' => 1,
                        ],
                        'page_role' => 'geometry_only',
                        'signals' => ['vector_geometry', 'table_candidate'],
                        'preview' => ['path' => null],
                    ]],
                    'metadata' => ['page_count' => 1],
                ];
            }
        };

        $result = (new PdfGeometryExtractor($worker))->extract('%PDF', 'drawing.pdf');

        self::assertSame('pymupdf', $result->provider);
        self::assertSame('geometry_v1', $result->model);
        self::assertSame(5, $result->pages[0]->pageNumber);
        self::assertSame(842, $result->pages[0]->width);
        self::assertSame(595, $result->pages[0]->height);
        self::assertSame('geometry_only', $result->pages[0]->pageRole);
        self::assertSame(42, $result->pages[0]->visualMetrics['line_count']);
        self::assertSame(['vector_geometry', 'table_candidate'], $result->pages[0]->signals);
        self::assertSame('Плита сбора мусора', $result->pages[0]->textBlocks[0]['text']);
        self::assertSame('line', $result->pages[0]->vectorElements[0]['kind']);
        self::assertSame($result->pages[0], $result->pageByNumber(5));
    }
}
