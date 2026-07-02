<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

final readonly class PdfGeometryExtractionResult
{
    /**
     * @param array<int, PdfGeometryPageData> $pages
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $pages,
        public array $metadata = [],
    ) {}

    public function pageByNumber(int $pageNumber): ?PdfGeometryPageData
    {
        foreach ($this->pages as $page) {
            if ($page->pageNumber === $pageNumber) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'pages' => array_map(
                static fn (PdfGeometryPageData $page): array => $page->toArray(),
                $this->pages
            ),
            'metadata' => $this->metadata,
        ];
    }
}
