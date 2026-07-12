<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use DOMDocument;
use InvalidArgumentException;

final class RecordedVisionSourceTraceVerifier
{
    public function verify(string $format, string $source, array $payload, array $trace): void
    {
        if (! hash_equals((string) ($trace['source_sha256'] ?? ''), hash('sha256', $source))) {
            throw new InvalidArgumentException('vision_trace_source_mismatch');
        }
        match ($format) {
            'ppm' => $this->raster($this->ppmPixels($source), $payload, $trace),
            'raster_pdf' => $this->raster($this->pdfPixels($source), $payload, $trace),
            'svg' => $this->svg($source, $payload, $trace),
            default => throw new InvalidArgumentException('vision_trace_format_invalid'),
        };
    }

    private function raster(array $image, array $payload, array $trace): void
    {
        [$width, $height, $pixels] = $image;
        foreach ($trace['walls'] ?? [] as $line) {
            if (! is_array($line) || ! $this->blackLine($pixels, $width, $height, $line)) {
                throw new InvalidArgumentException('vision_trace_wall_missing');
            }
        }
        foreach ($trace['labels'] ?? [] as $label) {
            if (! $this->bitmapLabel($pixels, $width, $height, $label)) {
                throw new InvalidArgumentException('vision_trace_dimension_label_missing');
            }
        }
        $rooms = array_values(array_filter($payload['elements'] ?? [], static fn (array $element): bool => ($element['type'] ?? null) === 'room'));
        $room = $rooms[0]['polygon'] ?? [];
        if ($room !== $trace['room_polygon'] || (float) ($payload['scale_candidates'][0]['meters_per_unit'] ?? 0) !== (float) $trace['meters_per_pixel']) {
            throw new InvalidArgumentException('vision_trace_geometry_mismatch');
        }
        if (abs(($trace['width_pixels'] * $trace['meters_per_pixel']) * ($trace['height_pixels'] * $trace['meters_per_pixel']) - 44.0) > 1.0e-9) {
            throw new InvalidArgumentException('vision_trace_area_mismatch');
        }
    }

    private function svg(string $source, array $payload, array $trace): void
    {
        $document = new DOMDocument;
        if (! $document->loadXML($source, LIBXML_NONET)) {
            throw new InvalidArgumentException('vision_trace_svg_invalid');
        }
        $ids = [];
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node->hasAttribute('id')) {
                $ids[$node->getAttribute('id')] = $node;
            }
        }
        foreach ($trace['source_ids'] ?? [] as $id) {
            if (! isset($ids[$id])) {
                throw new InvalidArgumentException('vision_trace_source_id_missing');
            }
        }
        foreach ($trace['text'] ?? [] as $id => $value) {
            if (! isset($ids[$id]) || trim($ids[$id]->textContent) !== $value) {
                throw new InvalidArgumentException('vision_trace_text_mismatch');
            }
        }
        foreach ($trace['attributes'] ?? [] as $id => $attributes) {
            if (! isset($ids[$id])) {
                throw new InvalidArgumentException('vision_trace_source_id_missing');
            }
            foreach ($attributes as $name => $value) {
                if ($ids[$id]->getAttribute($name) !== $value) {
                    throw new InvalidArgumentException('vision_trace_attribute_mismatch');
                }
            }
        }
        $evidence = array_column($payload['evidence'] ?? [], null, 'key');
        foreach ($trace['evidence_ids'] ?? [] as $evidenceId) {
            if (! isset($evidence[$evidenceId])) {
                throw new InvalidArgumentException('vision_trace_evidence_missing');
            }
        }
        foreach ($trace['element_points'] ?? [] as $key => $points) {
            $elements = array_column($payload['elements'] ?? [], null, 'key');
            if (($elements[$key]['polygon'] ?? null) !== $points) {
                throw new InvalidArgumentException('vision_trace_element_mismatch');
            }
        }
    }

    private function ppmPixels(string $source): array
    {
        if (preg_match('/\AP6\n(\d+) (\d+)\n255\n/s', $source, $match) !== 1) {
            throw new InvalidArgumentException('vision_trace_ppm_invalid');
        }
        return [(int) $match[1], (int) $match[2], substr($source, strlen($match[0]))];
    }

    private function pdfPixels(string $source): array
    {
        if (preg_match('/\/Subtype \/Image \/Width (\d+) \/Height (\d+).*?stream\n(.*?)\nendstream/s', $source, $match) !== 1) {
            throw new InvalidArgumentException('vision_trace_pdf_image_missing');
        }
        return [(int) $match[1], (int) $match[2], $match[3]];
    }

    private function blackLine(string $pixels, int $width, int $height, array $line): bool
    {
        [$x1, $y1, $x2, $y2] = $line;
        $steps = max(abs($x2 - $x1), abs($y2 - $y1));
        for ($step = 0; $step <= $steps; $step++) {
            $x = $x1 + (int) round(($x2 - $x1) * $step / max(1, $steps));
            $y = $y1 + (int) round(($y2 - $y1) * $step / max(1, $steps));
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height || substr($pixels, ($y * $width + $x) * 3, 3) !== "\0\0\0") {
                return false;
            }
        }
        return true;
    }

    private function bitmapLabel(string $pixels, int $width, int $height, array $label): bool
    {
        foreach ($label['black_pixels'] as [$dx, $dy]) {
            $offset = (($label['y'] + $dy) * $width + $label['x'] + $dx) * 3;
            if ($label['y'] + $dy >= $height || substr($pixels, $offset, 3) !== "\0\0\0") {
                return false;
            }
        }
        return true;
    }
}
