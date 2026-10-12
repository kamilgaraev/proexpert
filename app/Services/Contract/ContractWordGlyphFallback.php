<?php

declare(strict_types=1);

namespace App\Services\Contract;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Dompdf\FontMetrics;

final class ContractWordGlyphFallback
{
    public function apply(DOMDocument $document): void
    {
        $families = ContractDocumentFonts::families($document->saveHTML());
        if (array_intersect($families, array_keys(ContractFontCatalog::FILES)) !== []) {
            $metrics = ContractDocumentFonts::pdf($families)->getFontMetrics();
            $this->node($document, $document, $metrics, 'DejaVu Sans', false, false);
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('style')) $element->setAttribute('style', preg_replace_callback('/font-family\s*:\s*([^;]+)/i',
                static fn (array $match): string => 'font-family:'.trim(explode(',', $match[1])[0], " \t'\""), $element->getAttribute('style')));
        }
    }

    private function node(DOMDocument $document, DOMNode $node, FontMetrics $metrics, string $family, bool $bold, bool $italic): void
    {
        if ($node instanceof DOMElement) {
            $bold = in_array($node->tagName, ['b', 'strong'], true) || $bold;
            $italic = in_array($node->tagName, ['i', 'em'], true) || $italic;
            $style = $node->getAttribute('style');
            if (preg_match('/font-family\s*:\s*([^;]+)/i', $style, $match)) $family = ContractFontCatalog::family(trim(explode(',', $match[1])[0], " \t'\""));
            if (preg_match('/font-weight\s*:\s*(normal|bold|\d+)/i', $style, $match)) $bold = $match[1] === 'bold' || (int) $match[1] >= 600;
            if (preg_match('/font-style\s*:\s*(normal|italic|oblique)/i', $style, $match)) $italic = $match[1] !== 'normal';
        }
        if ($node instanceof DOMText && isset(ContractFontCatalog::FILES[$family]) && $node->data !== '') {
            $subtype = $bold && $italic ? 'bold_italic' : ($bold ? 'bold' : ($italic ? 'italic' : 'normal'));
            $mapped = $metrics->mapTextToFonts($node->data, [$family, 'DejaVu Sans'], $subtype, -1, true);
            if (count($mapped) > 1 || ($mapped[0]['font'] ?? null) !== $metrics->getFont($family, $subtype)) {
                $fragment = $document->createDocumentFragment();
                $offsets = array_keys($mapped);
                foreach ($offsets as $index => $offset) {
                    $part = $mapped[$offset];
                    $actual = $part['font'] === null ? $family : ContractDocumentFonts::actualFamily($metrics, $part['font']);
                    $span = $document->createElement('span');
                    $span->setAttribute('style', "font-family:'".$actual."'");
                    $end = $offsets[$index + 1] ?? mb_strlen($node->data);
                    $span->appendChild($document->createTextNode(mb_substr($node->data, $offset, $end - $offset)));
                    $fragment->appendChild($span);
                }
                $node->parentNode?->replaceChild($fragment, $node);
            }
            return;
        }
        foreach (iterator_to_array($node->childNodes) as $child) $this->node($document, $child, $metrics, $family, $bold, $italic);
    }
}
