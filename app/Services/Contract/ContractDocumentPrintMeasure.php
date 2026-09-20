<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\FrameDecorator\ListBullet;
use Dompdf\FrameDecorator\Text;
use Dompdf\Options;

final class ContractDocumentPrintMeasure
{
    public const PT_PER_MM = 72 / 25.4;

    public function measure(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
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
            $style = 'width:'.($layout['width'] * self::PT_PER_MM).'pt;page-break-after:always;'
                .'line-height:'.($layout['lineHeight'] ?? 1.4).';text-align:'.($layout['align'] ?? 'left').';'
                .'text-indent:'.(($layout['firstLineIndent'] ?? 0) * self::PT_PER_MM).'pt';
            $html .= '<section data-measure="'.$index.'" style="'.$style.'">'.$block['html'].'</section>';
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
            if ($frame instanceof Text || $frame instanceof ListBullet) {
                $text = $frame instanceof Text ? $frame->get_text()
                    : ($node instanceof DOMElement && $node->hasAttribute('dompdf-counter') ? $node->getAttribute('dompdf-counter').'.' : '•');
                if ($text === '') {
                    return;
                }
                $bold = $style->font_weight === 'bold' || (int) $style->font_weight >= 600;
                $italic = in_array($style->font_style, ['italic', 'oblique'], true);
                $size = (float) $style->font_size;
                $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', ($bold ? 'bold' : '').($italic ? 'italic' : '') ?: 'normal');
                $spacing = $frame instanceof Text ? $frame->get_text_spacing() + (float) $style->word_spacing : 0.0;
                $width = $pdf->getFontMetrics()->getTextWidth($text, $font, $size, $spacing, (float) $style->letter_spacing);
                if ($frame instanceof ListBullet) {
                    $x += $frame->get_width() - $width;
                }
                $item = ['type' => 'text', 'text' => $text, 'x' => (float) $x, 'y' => (float) $y,
                    'width' => $width, 'height' => $pdf->getFontMetrics()->getFontHeight($font, $size),
                    'fontSize' => $size, 'bold' => $bold, 'italic' => $italic, 'underline' => false, 'strike' => false,
                    'wordSpacing' => $spacing, 'letterSpacing' => (float) $style->letter_spacing, 'href' => null];
                for ($parent = $node->parentNode; $parent instanceof DOMElement && $parent !== $root; $parent = $parent->parentNode) {
                    if ($parent->tagName === 'u') {
                        $item['underline'] = true;
                    } elseif ($parent->tagName === 's') {
                        $item['strike'] = true;
                    } elseif ($parent->tagName === 'a') {
                        $item['href'] = $parent->getAttribute('href');
                    }
                }
                $measurements[$index]['items'][] = $item;
            } elseif ($node instanceof DOMElement && $node->tagName === 'td') {
                $box = $frame->get_border_box();
                $measurements[$index]['items'][] = ['type' => 'rect', 'x' => (float) $box['x'], 'y' => (float) $box['y'], 'width' => (float) $box['w'], 'height' => (float) $box['h']];
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
            if ($measurement['height'] > 19000) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
        }
        unset($measurement);

        return $measurements;
    }
}
