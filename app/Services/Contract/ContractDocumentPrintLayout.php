<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractDocumentPrintLayout
{
    public function render(array $layout, array $blocks): string
    {
        (new ContractDocumentLayoutValidator)->page($layout);
        $unit = ContractDocumentPrintMeasure::PT_PER_MM;
        [$width, $height] = $this->size($layout['page']);
        $margins = array_map(static fn ($value): float => $value * $unit, $layout['page']['margins']);
        $contentWidth = $width - $margins['left'] - $margins['right'];
        $contentHeight = $height - $margins['top'] - $margins['bottom'];
        foreach ($blocks as &$block) {
            $block['layout']['width'] = min($block['layout']['width'], $contentWidth / $unit);
            $block['layout']['x'] = max(0, min($block['layout']['x'], $contentWidth / $unit - $block['layout']['width']));
        }
        unset($block);
        $measured = (new ContractDocumentGroupMeasure)->measure($blocks);
        $order = array_keys($blocks);
        usort($order, static fn ($a, $b): int => $blocks[$a]['layout']['y'] <=> $blocks[$b]['layout']['y'] ?: $a <=> $b);
        $placed = [];
        $pages = [[]];
        foreach ($order as $index) {
            $block = $blocks[$index]['layout'];
            $measure = $measured[$index];
            $actualHeight = max($measure['height'], $block['minHeight'] * $unit, 1);
            $y = $block['y'] * $unit;
            if (($block['breakBefore'] ?? false) && fmod($y, $contentHeight) > .01) {
                $y = ceil($y / $contentHeight) * $contentHeight;
            }
            $x = $block['x'] * $unit;
            $w = $block['width'] * $unit;
            $gap = ($block['spaceAfter'] ?? 4) * $unit;
            $pieces = [];
            for ($attempt = 0; $attempt <= count($placed); $attempt++) {
                if ($actualHeight <= $contentHeight && fmod($y, $contentHeight) + $actualHeight > $contentHeight + .01) {
                    $y = (floor($y / $contentHeight) + 1) * $contentHeight;
                }
                $pieces = $this->split($measure['items'], $actualHeight, $y, $contentHeight);
                $last = $pieces[array_key_last($pieces)];
                $end = $last['page'] * $contentHeight + $last['top'] + $last['height'];
                $collision = null;
                foreach ($placed as $other) {
                    if ($x < $other['x'] + $other['width'] - .01 && $x + $w > $other['x'] + .01
                        && $y < $other['end'] + $other['gap'] - .01 && $end + $gap > $other['y'] + .01) {
                        $collision = $other;
                        break;
                    }
                }
                if ($collision === null) {
                    break;
                }
                $y = $collision['end'] + $collision['gap'];
            }
            $placed[] = ['x' => $x, 'y' => $y, 'width' => $w, 'end' => $end, 'gap' => $gap];
            foreach ($pieces as $piece) {
                while (count($pages) <= $piece['page']) {
                    $pages[] = [];
                }
                foreach ($measure['items'] as $item) {
                    if ($item['type'] === 'rect') {
                        $top = max($piece['offset'], $item['y']);
                        $bottom = min($piece['offset'] + $piece['height'], $item['y'] + $item['height']);
                        if ($bottom <= $top) {
                            continue;
                        }
                        $item['y'] = $top;
                        $item['height'] = $bottom - $top;
                    } elseif ($item['y'] < $piece['offset'] - .01 || $item['y'] >= $piece['offset'] + $piece['height'] - .01) {
                        continue;
                    }
                    $item['x'] += $margins['left'] + $x;
                    $item['y'] += $margins['top'] + $piece['top'] - $piece['offset'];
                    $pages[$piece['page']][] = $item;
                }
            }
        }

        return $this->html(['version' => 1, 'page' => $layout['page'], 'width' => $width, 'height' => $height, 'pages' => $pages]);
    }

    private function split(array $items, float $height, float $y, float $pageHeight): array
    {
        $lines = array_values(array_filter($items, static fn ($item): bool => $item['type'] === 'text'));
        $cuts = [];
        foreach ($lines as $line) {
            $cut = $line['y'] + $line['height'] + .5;
            if (array_filter($lines, static fn ($other): bool => $other['y'] < $cut && $cut < $other['y'] + $other['height']) === []) {
                $cuts[] = $cut;
            }
        }
        sort($cuts);
        $pieces = [];
        for ($offset = 0.0; $offset < $height - .01;) {
            $page = (int) floor($y / $pageHeight);
            if ($page > 500) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
            $top = fmod($y, $pageHeight);
            $end = min($height, $offset + $pageHeight - $top);
            if ($end < $height) {
                $safe = array_values(array_filter($cuts, static fn ($cut): bool => $cut > $offset + .01 && $cut <= $end));
                if ($safe !== []) {
                    $end = $safe[array_key_last($safe)];
                } elseif ($top > .01) {
                    $y = ($page + 1) * $pageHeight;

                    continue;
                }
            }
            $pieces[] = ['page' => $page, 'top' => $top, 'offset' => $offset, 'height' => $end - $offset];
            $offset = $end;
            $y = ($page + 1) * $pageHeight;
        }

        return $pieces;
    }

    public function size(array $page): array
    {
        [$width, $height] = match ($page['format']) {
            'A5' => [148, 210], 'Letter' => [215.9, 279.4], default => [210, 297]
        };
        if ($page['orientation'] === 'landscape') {
            [$width, $height] = [$height, $width];
        }

        return [$width * ContractDocumentPrintMeasure::PT_PER_MM, $height * ContractDocumentPrintMeasure::PT_PER_MM];
    }

    public function html(array $plan): string
    {
        $encoded = base64_encode(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $html = '<article data-contract-print="'.$encoded.'" style="margin:0;background:#eaf0f3"><style>'.ContractDocumentFonts::css($plan).'</style>';
        foreach ($plan['pages'] as $items) {
            $html .= '<section style="position:relative;width:'.$plan['width'].'pt;height:'.$plan['height'].'pt;background:white;margin:0 auto 16pt;overflow:hidden">';
            foreach ($items as $item) {
                $position = 'position:absolute;left:'.$item['x'].'pt;top:'.$item['y'].'pt;';
                if ($item['type'] === 'rect') {
                    $html .= '<span style="'.$position.'width:'.$item['width'].'pt;height:'.$item['height'].'pt;border:.5pt solid #aab3bf;box-sizing:border-box"></span>';
                } elseif ($item['type'] === 'anchor') {
                    $html .= '<span id="'.$this->escape($item['name']).'" style="'.$position.'"></span>';
                } else {
                    $decoration = trim(($item['underline'] ? 'underline ' : '').($item['strike'] ? 'line-through' : '')) ?: 'none';
                    $style = $position.'white-space:pre;font-family:MostContract,Arial,sans-serif;font-size:'.$item['fontSize'].'pt;line-height:1;'
                        .'font-weight:'.($item['bold'] ? 'bold' : 'normal').';font-style:'.($item['italic'] ? 'italic' : 'normal').';'
                        .'word-spacing:'.$item['wordSpacing'].'pt;letter-spacing:'.$item['letterSpacing'].'pt;text-decoration:'.$decoration.';color:#172033';
                    $text = $this->escape($item['text']);
                    $html .= $item['href'] !== null ? '<a href="'.$this->escape($item['href']).'" style="'.$style.'">'.$text.'</a>' : '<span style="'.$style.'">'.$text.'</span>';
                }
            }
            $html .= '</section>';
        }

        return $html.'</article>';
    }

    public function decode(string $html): ?array
    {
        if (! str_starts_with($html, '<article data-contract-print="')) {
            return null;
        }
        if (preg_match('/^<article data-contract-print="([A-Za-z0-9+\/=]+)"/', $html, $match) !== 1) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        $json = base64_decode($match[1], true);
        $plan = $json === false ? null : json_decode($json, true, 32);
        (new ContractPrintPlanValidator)->validate($plan);
        if ($this->html($plan) !== $html) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }

        return $plan;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
