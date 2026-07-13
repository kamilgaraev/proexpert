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
        $polygon = $this->deriveRoomPolygon($pixels, $width, $height);
        $dimensions = [];
        foreach ($trace['labels'] ?? [] as $label) {
            $decoded = $this->decodeBitmapLabel($pixels, $width, $height, $label['bbox']);
            if ($decoded !== $label['text'] || preg_match('/^(\d+\.\d+) m$/', $decoded, $match) !== 1) {
                throw new InvalidArgumentException('vision_trace_dimension_label_missing');
            }
            $dimensions[] = (float) $match[1];
        }
        $rooms = array_values(array_filter($payload['elements'] ?? [], static fn (array $element): bool => ($element['type'] ?? null) === 'room'));
        $room = $rooms[0]['polygon'] ?? [];
        $scale = (float) ($payload['scale_candidates'][0]['meters_per_unit'] ?? 0);
        if ($room !== $polygon || $room !== $trace['room_polygon'] || $scale !== (float) $trace['meters_per_unit']) {
            throw new InvalidArgumentException('vision_trace_geometry_mismatch');
        }
        if (abs((($polygon[1][0] - $polygon[0][0]) * $scale) * (($polygon[2][1] - $polygon[1][1]) * $scale) - 44.0) > 1.0e-9) {
            throw new InvalidArgumentException('vision_trace_area_mismatch');
        }
        $derivedX = $dimensions[0] / ($polygon[1][0] - $polygon[0][0]);
        $derivedY = $dimensions[1] / ($polygon[2][1] - $polygon[1][1]);
        if (abs($derivedX - $derivedY) > 1.0e-12 || abs($derivedX - $scale) > 1.0e-12) {
            throw new InvalidArgumentException('vision_trace_scale_mismatch');
        }
        foreach ($payload['scale_candidates'] as $candidate) {
            if (abs((float) $candidate['meters_per_unit'] - $scale) > 1.0e-12) {
                throw new InvalidArgumentException('vision_trace_scale_mismatch');
            }
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

    private function deriveRoomPolygon(string $pixels, int $width, int $height): array
    {
        $columns = [];
        $rows = [];
        for ($x = 0; $x < $width; $x++) {
            $count = 0;
            for ($y = 0; $y < $height; $y++) {
                $count += substr($pixels, ($y * $width + $x) * 3, 3) === "\0\0\0" ? 1 : 0;
            }if ($count >= 200) {
                $columns[] = $x;
            }
        }
        for ($y = 0; $y < $height; $y++) {
            $count = 0;
            for ($x = 0; $x < $width; $x++) {
                $count += substr($pixels, ($y * $width + $x) * 3, 3) === "\0\0\0" ? 1 : 0;
            }if ($count >= 250) {
                $rows[] = $y;
            }
        }
        $columnGroups = $this->groups($columns);
        $rowGroups = $this->groups($rows);
        if (count($columnGroups) < 2 || count($rowGroups) < 2) {
            throw new InvalidArgumentException('vision_trace_wall_missing');
        }

        return [[$columnGroups[0][0], $rowGroups[0][0]], [end($columnGroups[1]), $rowGroups[0][0]], [end($columnGroups[1]), end($rowGroups[1])], [$columnGroups[0][0], end($rowGroups[1])]];
    }

    private function decodeBitmapLabel(string $pixels, int $width, int $height, array $bbox): string
    {
        [$left,$top,$boxWidth,$boxHeight] = $bbox;
        if ($boxHeight !== 10 || $left < 1 || $top < 1 || $left + $boxWidth >= $width || $top + $boxHeight >= $height) {
            throw new InvalidArgumentException('vision_trace_label_bbox_invalid');
        }
        $grid = [];
        for ($gy = 0; $gy < 5; $gy++) {
            for ($gx = 0; $gx < (int) ($boxWidth / 2); $gx++) {
                $values = [];
                for ($sy = 0; $sy < 2; $sy++) {
                    for ($sx = 0; $sx < 2; $sx++) {
                        $values[] = substr($pixels, (($top + $gy * 2 + $sy) * $width + $left + $gx * 2 + $sx) * 3, 3);
                    }
                }if (count(array_unique($values)) !== 1) {
                    throw new InvalidArgumentException('vision_trace_glyph_stroke_invalid');
                }$grid[$gy][$gx] = $values[0] === "\0\0\0" ? '1' : '0';
            }
        }
        for ($x = $left - 1; $x <= $left + $boxWidth; $x++) {
            foreach ([$top - 1, $top + $boxHeight] as $y) {
                if (substr($pixels, ($y * $width + $x) * 3, 3) !== "\xff\xff\xff") {
                    throw new InvalidArgumentException('vision_trace_glyph_background_invalid');
                }
            }
        }
        $occupied = [];
        for ($x = 0; $x < count($grid[0]); $x++) {
            foreach ($grid as $row) {
                if ($row[$x] === '1') {
                    $occupied[] = $x;
                    break;
                }
            }
        }
        $groups = $this->groups($occupied);
        $font = ['111|101|111|101|111' => '8', '111|100|111|001|111' => '5', '111|101|101|101|111' => '0', '0|0|0|0|1' => '.', '00000|11011|10101|10101|10101' => 'm'];
        $text = '';
        $previous = null;
        foreach ($groups as $group) {
            if ($previous !== null && $group[0] - $previous > 2) {
                $text .= ' ';
            }$rows = [];
            foreach ($grid as $row) {
                $rows[] = implode('', array_slice($row, $group[0], end($group) - $group[0] + 1));
            }$key = implode('|', $rows);
            if (! isset($font[$key])) {
                throw new InvalidArgumentException('vision_trace_glyph_unknown');
            }$text .= $font[$key];
            $previous = end($group);
        }

        return $text;
    }

    private function groups(array $values): array
    {
        if ($values === []) {
            return [];
        }$groups = [[$values[0]]];
        foreach (array_slice($values, 1) as $value) {
            $last = count($groups) - 1;
            if ($value === end($groups[$last]) + 1) {
                $groups[$last][] = $value;
            } else {
                $groups[] = [$value];
            }
        }

return $groups;
    }
}
