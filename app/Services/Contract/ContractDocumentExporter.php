<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMDocument;
use DOMElement;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

final class ContractDocumentExporter
{
    public function render(string $html, string $format): string
    {
        $limit = str_starts_with($html, '<article data-contract-print="') ? 10 * 1024 * 1024 : 2 * 1024 * 1024;
        if (strlen($html) > $limit || ! in_array($format, ['docx', 'pdf'], true)) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        $plan = (new ContractDocumentPrintLayout)->decode($html);
        if ($plan !== null) {
            $bytes = (new ContractPositionedExporter)->render($plan, $format);
            if (strlen($bytes) > 20 * 1024 * 1024) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }

            return $bytes;
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->tagName === 'a' && preg_match('~^(?:https?://|mailto:|#)~i', $element->getAttribute('href')) !== 1) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
            if (! in_array($element->tagName, ['html', 'body', 'article', 'section', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'strong', 'em', 'u', 's', 'span', 'sup', 'sub', 'a', 'br', 'ol', 'ul', 'li', 'table', 'tr', 'td', 'thead', 'tbody', 'colgroup', 'col'], true)) {
                throw new ContractBuilderException('contracts.builder_export_invalid', 422);
            }
            foreach ($element->attributes as $attribute) {
                if (! in_array($attribute->name, ['class', 'id', 'href', 'rel', 'start', 'colspan', 'rowspan', 'style', 'data-contract-font', 'data-contract-color', 'data-contract-repeat-header', 'lang', 'dir'], true)) {
                    throw new ContractBuilderException('contracts.builder_export_invalid', 422);
                }
                if ($attribute->name === 'lang') ContractDocumentLocale::validate($attribute->value);
                if ($attribute->name === 'dir' && $attribute->value !== 'ltr') throw new ContractBuilderException('contracts.builder_export_invalid', 422);
                if ($attribute->name === 'style') { $this->validateStyle($attribute->value); }
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
        $pdf = ContractDocumentFonts::pdf(ContractDocumentFonts::families($html));
        $pdf->setPaper('A4');
        $locale = preg_match('/lang="(ru-RU|en-GB|en-US|de-DE|fr-FR)"/', $html, $localeMatch) === 1 ? $localeMatch[1] : 'ru-RU';
        $pdf->loadHtml('<!doctype html><html lang="'.$locale.'" dir="ltr"><head><meta charset="UTF-8"><style>'
            .'@page{margin:20mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.4;color:#172033}'
            .'p{margin:0 0 8pt}h1,h2,h3,h4,h5,h6{page-break-after:avoid}h1{font-size:18pt}h2{font-size:15pt}'
            .'table{width:100%;border-collapse:collapse;margin:8pt 0}td{border:0.5pt solid #aab3bf;padding:5pt;vertical-align:top;overflow-wrap:break-word}'
            .'.contract-work-specification{table-layout:fixed}.contract-specification-money{white-space:normal;overflow-wrap:break-word;font-size:9pt}'
            .'.contract-clause-number{font-weight:bold;page-break-after:avoid}a{color:#174c78}li{margin-bottom:4pt}'
            .'</style></head><body>'.$html.'</body></html>', 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    private function validateStyle(string $style): void
    {
        foreach (array_filter(explode(';', $style), static fn (string $part): bool => trim($part) !== '') as $part) {
            $pair = explode(':', $part, 2);
            $value = trim($pair[1] ?? '');
            $valid = match (trim($pair[0])) {
                'font-family' => in_array($value, array_merge(array_map(static fn (string $family): string => "'".$family."'", ContractFontCatalog::FAMILIES), array_map(ContractFontCatalog::css(...), ContractFontCatalog::FAMILIES)), true),
                'font-size' => preg_match('/^\d+(?:\.\d+)?pt$/D', $value) === 1 && (float) $value >= 8 && (float) $value <= 72,
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1,
                'text-align' => in_array($value, ['left', 'right', 'center', 'justify'], true),
                'line-height' => is_numeric($value) && (float) $value >= 1 && (float) $value <= 3,
                'text-indent', 'margin-bottom' => preg_match('/^\d+(?:\.\d+)?mm$/D', $value) === 1 && (float) $value <= 50,
                'width' => preg_match('/^\d+(?:\.\d+)?%$/D', $value) === 1 && (float) $value > 0 && (float) $value <= 100,
                default => false,
            };
            if (!$valid) { throw new ContractBuilderException('contracts.builder_export_invalid', 422); }
        }
    }

    private function docx(DOMDocument $document): string
    {
        (new ContractWordGlyphFallback)->apply($document);
        $word = new PhpWord;
        $locale = 'ru-RU';
        foreach ($document->getElementsByTagName('*') as $element) { if ($element->hasAttribute('lang')) { $locale = ContractDocumentLocale::validate($element->getAttribute('lang')); break; } }
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language($locale));
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
        if (! $body instanceof DOMElement) {
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
            (new ContractPositionedWordPackage)->finish($path, [], false);
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
            if (! $node instanceof DOMElement) {
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
        $widths = [];
        foreach ($node->getElementsByTagName('col') as $column) {
            if ($column->parentNode?->parentNode === $node && preg_match('/width:([0-9.]+)%/', $column->getAttribute('style'), $match) === 1) { $widths[] = (float) $match[1]; }
        }
        foreach ($node->getElementsByTagName('tr') as $rowNode) {
            $parent = $rowNode->parentNode;
            if ($parent !== $node && (!($parent instanceof DOMElement) || !in_array($parent->tagName, ['thead', 'tbody'], true) || $parent->parentNode !== $node)) {
                continue;
            }
            $row = $table->addRow(null, ['cantSplit' => true, 'tblHeader' => $rowNode->getAttribute('data-contract-repeat-header') === '1']);
            $cells = array_values(array_filter(iterator_to_array($rowNode->childNodes), static fn ($cell): bool => $cell instanceof DOMElement && $cell->tagName === 'td'));
            $index = $column = 0;
            while ($index < count($cells) || ($pending !== [] && $column <= max(array_keys($pending)))) {
                if (isset($pending[$column])) {
                    $merge = $pending[$column];
                    $row->addCell($widths === [] ? null : (int) round(array_sum(array_slice($widths, $column, $merge['span'])) * 96.38), ['gridSpan' => $merge['span'], 'vMerge' => 'continue']);
                    if (--$pending[$column]['remaining'] === 0) {
                        unset($pending[$column]);
                    }
                    $column += $merge['span'];

                    continue;
                }
                if (! isset($cells[$index])) {
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
                $this->blocks($cell, $row->addCell($widths === [] ? null : (int) round(array_sum(array_slice($widths, $column, $span)) * 96.38), $style));
                $column += $span;
            }
        }
    }

    private function bookmark(string $id): string
    {
        return 'clause_'.substr(hash('sha256', $id), 0, 24);
    }
}
