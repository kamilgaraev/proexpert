<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Smalot\PdfParser\Parser;
use Throwable;

final class BenchmarkFixtureDescriptorValidator
{
    /** @return array{page_count: int, has_text: bool, has_raster_image: bool} */
    public function pdf(string $path, string $sourceType): array
    {
        $content = @file_get_contents($path);
        if (! is_string($content) || ! str_starts_with($content, '%PDF-') || ! str_contains($content, '%%EOF')) {
            throw new BenchmarkManifestException('pdf_fixture_invalid');
        }
        try {
            $document = (new Parser)->parseContent($content);
            $pages = $document->getPages();
            $text = trim(implode("\n", array_map(static fn ($page): string => $page->getText(), $pages)));
        } catch (Throwable) {
            throw new BenchmarkManifestException('pdf_fixture_invalid');
        }
        $result = [
            'page_count' => count($pages),
            'has_text' => mb_strlen($text) >= 20,
            'has_raster_image' => preg_match('#/Subtype\s*/Image\b#', $content) === 1,
        ];
        if ($result['page_count'] !== 1
            || ($sourceType === BenchmarkSourceType::VectorPdf->value && (! $result['has_text'] || $result['has_raster_image']))
            || ($sourceType === BenchmarkSourceType::ScannedPdf->value && ($result['has_text'] || ! $result['has_raster_image']))) {
            throw new BenchmarkManifestException('pdf_fixture_type_invalid');
        }

        return $result;
    }
}
