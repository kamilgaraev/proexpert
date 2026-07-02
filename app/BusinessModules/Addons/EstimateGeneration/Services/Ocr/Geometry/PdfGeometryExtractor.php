<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

final class PdfGeometryExtractor
{
    public function __construct(private readonly PdfGeometryWorker $worker) {}

    public function extract(string $content, ?string $filename = null): PdfGeometryExtractionResult
    {
        $payload = $this->worker->extract($content, $filename);
        $pages = [];

        foreach (array_values($payload['pages'] ?? []) as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageNumber = $this->positiveInt($page['page_number'] ?? null);

            if ($pageNumber === null) {
                continue;
            }

            $pages[] = new PdfGeometryPageData(
                pageNumber: $pageNumber,
                width: $this->nullableInt($page['width'] ?? null),
                height: $this->nullableInt($page['height'] ?? null),
                rotation: $this->nullableInt($page['rotation'] ?? null),
                textBlocks: $this->listOfArrays($page['text_blocks'] ?? []),
                vectorElements: $this->listOfArrays($page['vector_elements'] ?? []),
                visualMetrics: $this->stringKeyArray($page['visual_metrics'] ?? []),
                pageRole: $this->pageRole($page['page_role'] ?? null),
                signals: array_values(array_unique(array_filter(array_map('strval', (array) ($page['signals'] ?? []))))),
                preview: $this->stringKeyArray($page['preview'] ?? []),
                rawPayload: $page,
            );
        }

        return new PdfGeometryExtractionResult(
            provider: trim((string) ($payload['provider'] ?? 'pymupdf')) ?: 'pymupdf',
            model: trim((string) ($payload['model'] ?? 'geometry_v1')) ?: 'geometry_v1',
            pages: $pages,
            metadata: $this->stringKeyArray($payload['metadata'] ?? []),
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function pageRole(mixed $value): string
    {
        $role = trim((string) $value);

        return in_array($role, ['plan', 'specification', 'title', 'detail', 'section', 'empty', 'geometry_only'], true)
            ? $role
            : 'empty';
    }
}
