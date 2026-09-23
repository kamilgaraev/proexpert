<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractDocumentGroupMeasure
{
    public function measure(array $blocks): array
    {
        $leaves = [];
        $collect = function (array $block, float $width, array $inherited = []) use (&$collect, &$leaves): array {
            foreach ($inherited as $field => $value) {
                $block['layout'][$field] ??= $value;
            }
            $typography = array_intersect_key($block['layout'], ['fontFamily' => true, 'fontSize' => true]);
            $block['layout']['width'] = min($width, $block['layout']['width']);
            $block['layout']['x'] = min($block['layout']['x'], $width - $block['layout']['width']);
            if (isset($block['children'])) {
                $block['children'] = array_map(fn ($child): array => $collect($child, $block['layout']['width'], $typography), $block['children']);
            } else {
                $block['measurement'] = count($leaves);
                $leaves[] = $block;
            }

            return $block;
        };
        $tree = array_map(fn ($block): array => $collect($block, $block['layout']['width'] + $block['layout']['x'], []), $blocks);
        $measurements = (new ContractDocumentPrintMeasure)->measure($leaves);
        $compose = function (array $block) use (&$compose, $measurements): array {
            if (isset($block['measurement'])) {
                return $measurements[$block['measurement']];
            }
            $children = $block['children'];
            usort($children, static fn ($a, $b): int => $a['layout']['y'] <=> $b['layout']['y']);
            $placed = [];
            $items = [];
            $height = 0;
            $unit = ContractDocumentPrintMeasure::PT_PER_MM;
            foreach ($children as $child) {
                $measurement = $compose($child);
                $layout = $child['layout'];
                $x = $layout['x'] * $unit;
                $y = $layout['y'] * $unit;
                $width = $layout['width'] * $unit;
                $childHeight = max($measurement['height'], $layout['minHeight'] * $unit);
                $gap = ($layout['spaceAfter'] ?? 4) * $unit;
                for ($attempt = 0; $attempt <= count($placed); $attempt++) {
                    $collision = false;
                    foreach ($placed as $other) {
                        if ($x < $other['x'] + $other['width'] - .01 && $x + $width > $other['x'] + .01
                            && $y < $other['end'] + $other['gap'] - .01 && $y + $childHeight + $gap > $other['y'] + .01) {
                            $y = $other['end'] + $other['gap'];
                            $collision = true;
                            break;
                        }
                    }
                    if (! $collision) {
                        break;
                    }
                }
                $placed[] = ['x' => $x, 'y' => $y, 'width' => $width, 'end' => $y + $childHeight, 'gap' => $gap];
                $height = max($height, $y + $childHeight);
                foreach ($measurement['items'] as $item) {
                    $item['x'] += $x;
                    $item['y'] += $y;
                    $items[] = $item;
                }
            }

            return ['items' => $items, 'height' => $height];
        };

        return array_map($compose, $tree);
    }
}
