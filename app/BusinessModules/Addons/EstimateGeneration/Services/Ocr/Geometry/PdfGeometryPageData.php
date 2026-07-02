<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

final readonly class PdfGeometryPageData
{
    /**
     * @param array<int, array<string, mixed>> $textBlocks
     * @param array<int, array<string, mixed>> $vectorElements
     * @param array<string, mixed> $visualMetrics
     * @param array<int, string> $signals
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public int $pageNumber,
        public ?int $width,
        public ?int $height,
        public ?int $rotation,
        public array $textBlocks,
        public array $vectorElements,
        public array $visualMetrics,
        public string $pageRole,
        public array $signals = [],
        public array $preview = [],
        public array $rawPayload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page_number' => $this->pageNumber,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'text_blocks' => $this->textBlocks,
            'vector_elements' => $this->vectorElements,
            'visual_metrics' => $this->visualMetrics,
            'page_role' => $this->pageRole,
            'signals' => $this->signals,
            'preview' => $this->preview,
            'overlay' => $this->overlay(),
        ];
    }

    public function text(): string
    {
        return trim(implode("\n", array_filter(array_map(
            static fn (array $block): string => trim((string) ($block['text'] ?? '')),
            $this->textBlocks
        ))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overlay(): array
    {
        return array_values(array_filter(array_map(
            static function (array $element): ?array {
                if (! is_array($element['bbox'] ?? null)) {
                    return null;
                }

                return [
                    'type' => (string) ($element['kind'] ?? 'path'),
                    'bbox' => $element['bbox'],
                    'geometry' => is_array($element['geometry'] ?? null) ? $element['geometry'] : [],
                ];
            },
            array_slice($this->vectorElements, 0, 200)
        )));
    }
}
