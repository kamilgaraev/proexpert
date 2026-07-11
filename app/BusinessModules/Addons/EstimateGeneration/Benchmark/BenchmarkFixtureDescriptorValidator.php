<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use DOMDocument;
use DOMElement;
use DOMNode;
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
        $offset = 0;
        $magic = $this->nextPpmToken($content, $offset);
        $widthToken = $this->nextPpmToken($content, $offset);
        $heightToken = $this->nextPpmToken($content, $offset);
        $maxToken = $this->nextPpmToken($content, $offset);
        if (! in_array($magic, ['P3', 'P5', 'P6'], true)) {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        $width = filter_var($widthToken, FILTER_VALIDATE_INT);
        $height = filter_var($heightToken, FILTER_VALIDATE_INT);
        $max = filter_var($maxToken, FILTER_VALIDATE_INT);
        if (! is_int($width) || ! is_int($height) || ! is_int($max) || $width < 1 || $height < 1
            || $width > 10_000 || $height > 10_000 || $width > intdiv(20_000_000, $height)
            || $max < 1 || $max > 65_535) {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        $samples = $width * $height * ($magic === 'P6' || $magic === 'P3' ? 3 : 1);
        if ($magic === 'P3') {
            for ($index = 0; $index < $samples; $index++) {
                $token = $this->nextPpmToken($content, $offset);
                if ($token === null || ! preg_match('/^[0-9]{1,5}$/', $token) || (int) $token > $max) {
                    throw new BenchmarkManifestException('ppm_fixture_invalid');
                }
            }
            if ($this->nextPpmToken($content, $offset) !== null) {
                throw new BenchmarkManifestException('ppm_fixture_invalid');
            }

            return;
        }
        $length = strlen($content);
        if ($offset >= $length || ! str_contains(" \t\r\n", $content[$offset])) {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        $offset += $content[$offset] === "\r" && ($content[$offset + 1] ?? '') === "\n" ? 2 : 1;
        $bytesPerSample = $max > 255 ? 2 : 1;
        if ($samples > intdiv(64_000_000, $bytesPerSample)
            || $length - $offset !== $samples * $bytesPerSample) {
            throw new BenchmarkManifestException('ppm_fixture_invalid');
        }
        for ($sample = 0; $sample < $samples; $sample++) {
            $position = $offset + ($sample * $bytesPerSample);
            $value = $bytesPerSample === 1
                ? ord($content[$position])
                : (ord($content[$position]) << 8) | ord($content[$position + 1]);
            if ($value > $max) {
                throw new BenchmarkManifestException('ppm_fixture_invalid');
            }
        }
    }

    private function svg(string $content): void
    {
        if (strlen($content) > 4_000_000 || preg_match('/<!DOCTYPE|<!ENTITY|url\s*\(|@import|expression\s*\(/i', $content)) {
            throw new BenchmarkManifestException('svg_fixture_invalid');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
                || $document->doctype !== null || ! $document->documentElement instanceof DOMElement) {
                throw new BenchmarkManifestException('svg_fixture_invalid');
            }
            $root = $document->documentElement;
            if ($root->localName !== 'svg' || ! in_array($root->namespaceURI, [null, '', 'http://www.w3.org/2000/svg'], true)
                || (! $root->hasAttribute('viewBox') && (! $root->hasAttribute('width') || ! $root->hasAttribute('height')))) {
                throw new BenchmarkManifestException('svg_fixture_invalid');
            }
            $allowedElements = ['svg', 'g', 'title', 'desc', 'path', 'rect', 'line', 'polyline', 'polygon', 'circle', 'ellipse', 'text'];
            $allowedAttributes = [
                'xmlns', 'viewBox', 'width', 'height', 'id', 'transform', 'fill', 'stroke', 'stroke-width',
                'd', 'points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'font-size',
            ];
            $geometry = 0;
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! $element instanceof DOMElement || ! in_array($element->localName, $allowedElements, true)
                    || ! in_array($element->namespaceURI, [null, '', 'http://www.w3.org/2000/svg'], true)) {
                    throw new BenchmarkManifestException('svg_fixture_invalid');
                }
                if (in_array($element->localName, ['path', 'rect', 'line', 'polyline', 'polygon', 'circle', 'ellipse'], true)) {
                    $geometry++;
                }
                foreach ($element->attributes as $attribute) {
                    if (str_starts_with(strtolower($attribute->localName), 'on')
                        || ! in_array($attribute->nodeName, $allowedAttributes, true)
                        || preg_match('/url\s*\(|@import|expression\s*\(/i', $attribute->nodeValue ?? '')) {
                        throw new BenchmarkManifestException('svg_fixture_invalid');
                    }
                }
            }
            if ($geometry < 1) {
                throw new BenchmarkManifestException('svg_fixture_invalid');
            }
            $this->assertSafeSvgNodes($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function assertSafeSvgNodes(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $this->assertSafeSvgNodes($child);

                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                if (trim($child->nodeValue ?? '') !== ''
                    && (! $node instanceof DOMElement || ! in_array($node->localName, ['title', 'desc', 'text'], true))) {
                    throw new BenchmarkManifestException('svg_fixture_invalid');
                }

                continue;
            }
            throw new BenchmarkManifestException('svg_fixture_invalid');
        }
    }

    private function nextPpmToken(string $content, int &$offset): ?string
    {
        $length = strlen($content);
        while ($offset < $length) {
            $character = $content[$offset];
            if (str_contains(" \t\r\n", $character)) {
                $offset++;

                continue;
            }
            if ($character !== '#') {
                break;
            }
            $lineEnd = strpos($content, "\n", $offset);
            if ($lineEnd === false || $lineEnd - $offset > 65_536 || $lineEnd > 131_072) {
                throw new BenchmarkManifestException('ppm_fixture_invalid');
            }
            $offset = $lineEnd + 1;
        }
        if ($offset >= $length || $offset > 131_072) {
            return null;
        }
        $start = $offset;
        while ($offset < $length && ! str_contains(" \t\r\n#", $content[$offset])) {
            if ($offset - $start > 32) {
                throw new BenchmarkManifestException('ppm_fixture_invalid');
            }
            $offset++;
        }

        return substr($content, $start, $offset - $start);
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
