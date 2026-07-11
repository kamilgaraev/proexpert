<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Smalot\PdfParser\Parser;
use Throwable;

final class BenchmarkFixtureDescriptorValidator
{
    private const EXTENSIONS = [
        'vector_pdf' => 'pdf',
        'scanned_pdf' => 'pdf',
        'photo_plan' => 'ppm',
        'dimensioned_sketch' => 'svg',
        'undimensioned_sketch' => 'svg',
        'dwg' => 'dwg',
        'dxf' => 'dxf',
    ];

    /** @param list<string> $allowedCapabilities */
    public function validateBytes(
        string $content,
        BenchmarkSourceType $sourceType,
        string $locator,
        array $allowedCapabilities,
    ): void {
        if ($content === '' || strlen($content) > 64_000_000
            || strtolower((string) pathinfo($locator, PATHINFO_EXTENSION)) !== self::EXTENSIONS[$sourceType->value]) {
            throw new BenchmarkManifestException('fixture_descriptor_mismatch');
        }
        match ($sourceType) {
            BenchmarkSourceType::VectorPdf, BenchmarkSourceType::ScannedPdf => $this->pdfBytes($content, $sourceType->value),
            BenchmarkSourceType::PhotoPlan => $this->ppm($content),
            BenchmarkSourceType::DimensionedSketch, BenchmarkSourceType::UndimensionedSketch => $this->svg($content),
            BenchmarkSourceType::Dxf => $this->dxf($content),
            BenchmarkSourceType::Dwg => $this->dwg($content, $allowedCapabilities),
        };
    }

    /** @return array{page_count: int, has_text: bool, has_raster_image: bool} */
    public function pdf(string $path, string $sourceType): array
    {
        $content = @file_get_contents($path);
        if (! is_string($content)) {
            throw new BenchmarkManifestException('pdf_fixture_invalid');
        }

        return $this->pdfBytes($content, $sourceType);
    }

    /** @return array{page_count: int, has_text: bool, has_raster_image: bool} */
    private function pdfBytes(string $content, string $sourceType): array
    {
        if (! str_starts_with($content, '%PDF-') || ! str_contains($content, '%%EOF')) {
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

    private function ppm(string $content): void
    {
        $withoutComments = preg_replace('/#[^\r\n]*/', '', $content);
        $tokens = preg_split('/\s+/', trim((string) $withoutComments));
        if (! is_array($tokens) || count($tokens) < 4 || array_shift($tokens) !== 'P3') {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        $width = filter_var(array_shift($tokens), FILTER_VALIDATE_INT);
        $height = filter_var(array_shift($tokens), FILTER_VALIDATE_INT);
        $max = filter_var(array_shift($tokens), FILTER_VALIDATE_INT);
        if (! is_int($width) || ! is_int($height) || ! is_int($max) || $width < 1 || $height < 1
            || $width > 10_000 || $height > 10_000 || $width * $height > 20_000_000 || $max < 1 || $max > 65_535
            || count($tokens) !== $width * $height * 3) {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        foreach ($tokens as $token) {
            if (! preg_match('/^[0-9]{1,5}$/', $token) || (int) $token > $max) {
                throw new BenchmarkManifestException('ppm_fixture_invalid');
            }
        }
    }

    private function svg(string $content): void
    {
        if (strlen($content) > 4_000_000 || preg_match('/<!DOCTYPE|<!ENTITY|<script\b|\b(?:href|src)\s*=\s*["\']\s*(?:https?:|\/\/|data:)/i', $content)
            || ! preg_match('/^\s*<svg\b[^>]*(?:viewBox|width)\s*=/i', $content)
            || ! preg_match('/<(?:path|rect|line|polyline|polygon|circle|ellipse)\b/i', $content)
            || ! preg_match('/<\/svg>\s*$/i', $content)) {
            throw new BenchmarkManifestException('svg_fixture_invalid');
        }
    }

    private function dxf(string $content): void
    {
        $normalized = "\n".str_replace(["\r\n", "\r"], "\n", trim($content))."\n";
        if (strlen($content) > 16_000_000
            || ! str_contains($normalized, "\n0\nSECTION\n2\nHEADER\n")
            || ! str_contains($normalized, "\n0\nSECTION\n2\nENTITIES\n")
            || substr_count($normalized, "\n0\nENDSEC\n") < 2
            || ! str_ends_with($normalized, "\n0\nEOF\n")) {
            throw new BenchmarkManifestException('dxf_fixture_invalid');
        }
    }

    /** @param list<string> $allowedCapabilities */
    private function dwg(string $content, array $allowedCapabilities): void
    {
        if (! in_array('descriptor_validation', $allowedCapabilities, true)
            || ! in_array('unsupported_conversion', $allowedCapabilities, true)
            || preg_match('/^AC(?:1015|1018|1021|1024|1027|1032) SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1\n?$/', $content) !== 1) {
            throw new BenchmarkManifestException('dwg_fixture_invalid');
        }
    }
}
