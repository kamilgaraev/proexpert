<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMElement;
use DOMNode;
use Dompdf\Frame;
use Dompdf\FrameDecorator\ListBullet;
use Dompdf\FrameDecorator\Text;
use Dompdf\FrameDecorator\Image;

final class ContractDocumentPrintMeasure
{
    public const PT_PER_MM = 72 / 25.4;

    public function measure(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }
        $families = [];
        foreach ($blocks as $block) array_push($families, $block['layout']['fontFamily'] ?? 'DejaVu Sans', ...ContractDocumentFonts::families($block['html']));
        $pdf = ContractDocumentFonts::pdf($families);
        $pdf->setPaper([0, 0, 900, 20000]);
        $measurements = [];
        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>'
            .'@page{margin:0}body{margin:0;font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4}'
            .'p{margin:0 0 8pt;white-space:pre-wrap;overflow-wrap:break-word}h1,h2,h3,h4,h5,h6{margin:0 0 8pt;page-break-after:avoid}'
            .'h1{font-size:18pt}h2{font-size:15pt}h3{font-size:13pt}h4{font-size:12pt}h5{font-size:11pt}h6{font-size:10pt}'
            .'table{width:100%;border-collapse:collapse;margin:0}td{border:.5pt solid #aab3bf;padding:5pt;vertical-align:top;overflow-wrap:break-word}'
            .'ul,ol{margin:0;padding-left:7mm}li{margin:0}.contract-clause-number{font-weight:bold}'
            .'</style></head><body>';
        foreach ($blocks as $index => $block) {
            $layout = $block['layout'];
            $measurements[$index] = ['items' => [], 'height' => 0.0, 'origin' => 0.0];
            $family = $layout['fontFamily'] ?? 'DejaVu Sans';
            if ($family === 'Most Contract') {
                $family = 'DejaVu Sans';
            }
            $cssFamily = ContractFontCatalog::css($family);
            $style = 'width:'.($layout['width'] * self::PT_PER_MM).'pt;page-break-after:always;'
                .'line-height:'.($layout['lineHeight'] ?? 1.4).';text-align:'.($layout['align'] ?? 'left').';'
                .'text-indent:'.(($layout['firstLineIndent'] ?? 0) * self::PT_PER_MM).'pt;font-family:'.$cssFamily.';'
                .(isset($layout['fontSize']) ? 'font-size:'.$layout['fontSize'].'pt;' : '');
            $html .= '<section data-measure="'.$index.'" data-font-family="'.htmlspecialchars($family, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="'.htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$block['html'].'</section>';
        }
        $html .= '</body></html>';
        $pdf->setCallbacks([['event' => 'end_frame', 'f' => function (Frame $frame) use (&$measurements, $pdf): void {
            $node = $frame->get_node();
            $root = $node;
            while ($root !== null && ! ($root instanceof DOMElement && $root->hasAttribute('data-measure'))) {
                $root = $root->parentNode;
            }
            if (! $root instanceof DOMElement) {
                return;
            }
            $index = (int) $root->getAttribute('data-measure');
            if ($node === $root) {
                if (isset($measurements[$index]['complete'])) {
                    throw new ContractBuilderException('contracts.builder_export_invalid', 422);
                }
                $measurements[$index]['complete'] = true;
                $box = $frame->get_padding_box();
                $measurements[$index]['height'] = max($measurements[$index]['height'], (float) $box['h']);
                $measurements[$index]['origin'] = (float) $box['y'];

                return;
            }
            [$x, $y] = $frame->get_position();
            $style = $frame->get_style();
            if ($frame instanceof Image && $node instanceof DOMElement) {
                $box = $frame->get_border_box();
                $source = $node->getAttribute('src');
                ContractInlineImage::bytes($source);
                $this->addItem($measurements[$index], $node, ['type' => 'image', 'x' => (float) $box['x'], 'y' => (float) $box['y'],
                    'width' => (float) $box['w'], 'height' => (float) $box['h'], 'src' => $source, 'alt' => $node->getAttribute('alt')]);
            } elseif ($frame instanceof Text || $frame instanceof ListBullet) {
                $text = $frame instanceof Text ? $frame->get_text()
                    : ($node instanceof DOMElement && $node->hasAttribute('dompdf-counter') ? $node->getAttribute('dompdf-counter').'.' : '•');
                if ($text === '') {
                    return;
                }
                $bold = $style->font_weight === 'bold' || (int) $style->font_weight >= 600;
                $italic = in_array($style->font_style, ['italic', 'oblique'], true);
                $size = (float) $style->font_size;
                $family = $root->getAttribute('data-font-family');
                $color = '#172033';
                $familyFound = false;
                $colorFound = false;
                for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                    if (!$familyFound && $parent->hasAttribute('data-contract-font')) {
                        $family = $parent->getAttribute('data-contract-font');
                        $familyFound = true;
                    }
                    if (!$colorFound && $parent->hasAttribute('data-contract-color')) {
                        $color = $parent->getAttribute('data-contract-color');
                        $colorFound = true;
                    }
                    if ($parent === $root) {
                        break;
                    }
                }
                if ($family === 'Most Contract') {
                    $family = 'DejaVu Sans';
                }
                $font = (string) $style->font_family;
                $family = ContractDocumentFonts::actualFamily($pdf->getFontMetrics(), $font);
                $spacing = $frame instanceof Text ? $frame->get_text_spacing() + (float) $style->word_spacing : 0.0;
                $width = $pdf->getFontMetrics()->getTextWidth($text, $font, $size, $spacing, (float) $style->letter_spacing);
                if ($frame instanceof ListBullet) {
                    $x += $frame->get_width() - $width;
                }
                $item = ['type' => 'text', 'text' => $text, 'x' => (float) $x, 'y' => (float) $y,
                    'width' => $width, 'height' => $pdf->getFontMetrics()->getFontHeight($font, $size),
                    'fontFamily' => $family, 'fontSize' => $size, 'bold' => $bold, 'italic' => $italic, 'underline' => false, 'strike' => false,
                    'wordSpacing' => $spacing, 'letterSpacing' => (float) $style->letter_spacing, 'href' => null, 'color' => $color];
                for ($parent = $node->parentNode; $parent instanceof DOMElement && $parent !== $root; $parent = $parent->parentNode) {
                    if ($parent->tagName === 'u') {
                        $item['underline'] = true;
                    } elseif ($parent->tagName === 's') {
                        $item['strike'] = true;
                    } elseif ($parent->tagName === 'a') {
                        $item['href'] = $parent->getAttribute('href');
                    }
                }
                $this->addItem($measurements[$index], $node, $item);
            } elseif ($node instanceof DOMElement && $node->tagName === 'td') {
                $box = $frame->get_border_box();
                $this->addItem($measurements[$index], $node, ['type' => 'rect', 'x' => (float) $box['x'], 'y' => (float) $box['y'], 'width' => (float) $box['w'], 'height' => (float) $box['h']]);
            } elseif ($node instanceof DOMElement && $node->tagName === 'table') {
                $box = $frame->get_border_box();
                $measurements[$index]['table_bounds'][$node->getNodePath()] = (float) $box['y'] + (float) $box['h'];
            } elseif ($node instanceof DOMElement && $node->hasAttribute('id')) {
                $measurements[$index]['items'][] = ['type' => 'anchor', 'name' => $node->getAttribute('id'), 'x' => (float) $x, 'y' => (float) $y, 'width' => 0, 'height' => 0];
            }
        }]]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        foreach ($measurements as &$measurement) {
            foreach ($measurement['items'] as &$item) {
                $item['y'] -= $measurement['origin'];
                $measurement['height'] = max($measurement['height'], $item['y'] + $item['height']);
            }
            unset($item);
            foreach ($measurement['headers'] ?? [] as $key => $header) {
                $top = min(array_column($header, 'y'));
                $bottom = max(array_map(static fn (array $item): float => $item['y'] + $item['height'], $header));
                foreach ($header as &$headerItem) { $headerItem['y'] -= $measurement['origin']; }
                unset($headerItem);
                $measurement['headers'][$key] = ['top' => $top - $measurement['origin'], 'height' => $bottom - $top,
                    'bottom' => ($measurement['table_bounds'][$key] ?? $bottom) - $measurement['origin'], 'items' => $header];
            }
            $measurement['headers'] = array_values($measurement['headers'] ?? []);
            if ($measurement['height'] > 19000) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
        }
        unset($measurement);

        return $measurements;
    }

    private function addItem(array &$measurement, DOMNode $node, array $item): void
    {
        $measurement['items'][] = $item;
        $header = false;
        for ($parent = $node; $parent instanceof DOMNode; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && $parent->getAttribute('data-contract-repeat-header') === '1') { $header = true; }
            if ($parent instanceof DOMElement && $parent->tagName === 'table') {
                if ($header) { $measurement['headers'][$parent->getNodePath()][] = $item; }
                break;
            }
        }
    }
}
