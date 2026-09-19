<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

final class ContractDocumentExporter
{
    public function render(string $html, string $format): string
    {
        if (strlen($html) > 2 * 1024 * 1024 || !in_array($format, ['docx', 'pdf'], true)) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->tagName === 'a' && preg_match('~^(?:https?://|mailto:|#)~i', $element->getAttribute('href')) !== 1) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
            if (!in_array($element->tagName, ['html', 'body', 'article', 'section', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'strong', 'em', 'u', 's', 'a', 'br', 'ol', 'ul', 'li', 'table', 'tr', 'td'], true)) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
            foreach ($element->attributes as $attribute) {
                if (!in_array($attribute->name, ['class', 'id', 'href', 'rel', 'start', 'colspan', 'rowspan'], true)) {
                    throw new ContractBuilderException('contracts.builder_export_invalid', 422);
                }
            }
        }

        $bytes = $format === 'pdf' ? $this->pdf($html) : $this->docx($document);
        if (strlen($bytes) > 20 * 1024 * 1024) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }

        return $bytes;
    }

    private function pdf(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml('<!doctype html><html lang="ru"><head><meta charset="UTF-8"><style>'
            .'@page{margin:20mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4;color:#172033}'
            .'p{margin:0 0 8pt}h1,h2,h3,h4,h5,h6{page-break-after:avoid}h1{font-size:18pt}h2{font-size:15pt}'
            .'table{width:100%;border-collapse:collapse;margin:8pt 0}td{border:0.5pt solid #aab3bf;padding:5pt;vertical-align:top;overflow-wrap:break-word}'
            .'.contract-clause-number{font-weight:bold;page-break-after:avoid}a{color:#174c78}li{margin-bottom:4pt}'
            .'</style></head><body>'.$html.'</body></html>', 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    private function docx(DOMDocument $document): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceAfter' => 120, 'lineHeight' => 1.2]);
        foreach ([18, 15, 13, 12, 11, 10] as $index => $size) {
            $word->addTitleStyle($index + 1, ['size' => $size, 'bold' => true], ['spaceAfter' => 180, 'keepNext' => true]);
        }
        $section = $word->addSection(['paperSize' => 'A4', 'marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134]);
        foreach ($document->getElementsByTagName('a') as $link) {
            $link->setAttribute('style', 'color:#174c78;text-decoration:underline');
            if (str_starts_with($link->getAttribute('href'), '#')) {
                $link->setAttribute('href', '#'.$this->bookmark(substr($link->getAttribute('href'), 1)));
            }
        }
        while (($strike = $document->getElementsByTagName('s')->item(0)) !== null) {
            $span = $document->createElement('span');
            $span->setAttribute('style', 'text-decoration:line-through');
            while ($strike->firstChild !== null) {
                $span->appendChild($strike->firstChild);
            }
            $strike->parentNode?->replaceChild($span, $strike);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        $this->blocks($body, $section);
        $path = tempnam(sys_get_temp_dir(), 'most-contract-export-');
        if ($path === false) {
            throw new ContractBuilderException('contracts.builder_export_failed', 503);
        }
        $escaping = \PhpOffice\PhpWord\Settings::isOutputEscapingEnabled();
        try {
            \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }

            return $bytes;
        } finally {
            \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled($escaping);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function blocks(DOMElement $parent, AbstractContainer $container): void
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if (in_array($node->tagName, ['article', 'section'], true)) {
                if ($node->hasAttribute('id')) {
                    $container->addBookmark($this->bookmark($node->getAttribute('id')));
                }
                $this->blocks($node, $container);
            } elseif ($node->tagName === 'table') {
                $this->table($node, $container);
                $container->addTextBreak();
            } else {
                if ($node->getAttribute('class') === 'contract-clause-number') {
                    $node->setAttribute('style', 'font-weight:bold');
                }
                Html::addHtml($container, $node->ownerDocument->saveXML($node), false, true);
            }
        }
    }

    private function table(DOMElement $node, AbstractContainer $container): void
    {
        $table = $container->addTable(['borderSize' => 6, 'borderColor' => 'AAB3BF', 'cellMargin' => 80, 'width' => 5000, 'unit' => 'pct']);
        $pending = [];
        foreach ($node->childNodes as $rowNode) {
            if (!$rowNode instanceof DOMElement || $rowNode->tagName !== 'tr') {
                continue;
            }
            $row = $table->addRow(null, ['cantSplit' => true]);
            $cells = array_values(array_filter(iterator_to_array($rowNode->childNodes), static fn ($cell): bool => $cell instanceof DOMElement && $cell->tagName === 'td'));
            $index = $column = 0;
            while ($index < count($cells) || ($pending !== [] && $column <= max(array_keys($pending)))) {
                if (isset($pending[$column])) {
                    $merge = $pending[$column];
                    $row->addCell(null, ['gridSpan' => $merge['span'], 'vMerge' => 'continue']);
                    if (--$pending[$column]['remaining'] === 0) {
                        unset($pending[$column]);
                    }
                    $column += $merge['span'];
                    continue;
                }
                if (!isset($cells[$index])) {
                    $row->addCell();
                    $column++;
                    continue;
                }
                $cell = $cells[$index++];
                $span = max(1, (int) $cell->getAttribute('colspan'));
                $rows = max(1, (int) $cell->getAttribute('rowspan'));
                $style = ['gridSpan' => $span, 'valign' => 'top'];
                if ($rows > 1) {
                    $style['vMerge'] = 'restart';
                    $pending[$column] = ['span' => $span, 'remaining' => $rows - 1];
                }
                $this->blocks($cell, $row->addCell(null, $style));
                $column += $span;
            }
        }
    }

    private function bookmark(string $id): string
    {
        return 'clause_'.substr(hash('sha256', $id), 0, 24);
    }
}
