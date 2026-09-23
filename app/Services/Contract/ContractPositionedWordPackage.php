<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

final class ContractPositionedWordPackage
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function finish(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ContractBuilderException('contracts.builder_export_failed', 503);
        }
        try {
            $document = $this->read($zip, 'word/document.xml');
            $query = new DOMXPath($document);
            $query->registerNamespace('w', self::W);
            $body = $query->query('/w:document/w:body')->item(0);
            $anchor = null;
            foreach (iterator_to_array($body->childNodes) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                if ($node->localName === 'p' && $query->query('w:pPr/w:sectPr', $node)->length > 0) {
                    $anchor = null;
                    $this->compactParagraph($document, $node);

                    continue;
                }
                if ($node->localName !== 'p') {
                    continue;
                }
                if ($anchor === null) {
                    $anchor = $node;
                    $this->compactParagraph($document, $anchor);

                    continue;
                }
                foreach (iterator_to_array($node->childNodes) as $run) {
                    if ($run instanceof DOMElement && $run->localName !== 'pPr') {
                        $anchor->appendChild($run);
                    }
                }
                $body->removeChild($node);
            }
            $zip->addFromString('word/document.xml', $document->saveXML());
            $fonts = $this->read($zip, 'word/fontTable.xml');
            $query = new DOMXPath($fonts);
            $query->registerNamespace('w', self::W);
            $relations = new DOMDocument('1.0', 'UTF-8');
            $root = $relations->appendChild($relations->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationships'));
            $families = ['DejaVu Sans' => 'sans', 'DejaVu Serif' => 'serif', 'DejaVu Sans Mono' => 'mono'];
            $variants = ['regular' => 'Regular', 'bold' => 'Bold', 'italic' => 'Italic', 'bolditalic' => 'BoldItalic'];
            foreach ($families as $familyName => $slug) {
                $font = $query->query('/w:fonts/w:font[@w:name="'.$familyName.'"]')->item(0);
                if (! $font instanceof DOMElement) {
                    $font = $fonts->createElementNS(self::W, 'w:font');
                    $font->setAttributeNS(self::W, 'w:name', $familyName);
                    $fonts->documentElement->appendChild($font);
                }
                foreach ($variants as $variant => $name) {
                    $id = $slug === 'sans' ? 'rIdContract'.$name : 'rIdContract'.ucfirst($slug).$name;
                    $file = $slug === 'sans' ? $variant.'.odttf' : $slug.'-'.$variant.'.odttf';
                    $relation = $relations->createElement('Relationship');
                    $relation->setAttribute('Id', $id);
                    $relation->setAttribute('Type', self::R.'/font');
                    $relation->setAttribute('Target', 'fonts/'.$file);
                    $root->appendChild($relation);
                    $embed = $fonts->createElementNS(self::W, 'w:embed'.$name);
                    $embed->setAttributeNS(self::R, 'r:id', $id);
                    $embed->setAttributeNS(self::W, 'w:fontKey', '{00000000-0000-0000-0000-000000000000}');
                    $font->appendChild($embed);
                    $zip->addFromString('word/fonts/'.$file, ContractDocumentFonts::bytes($variant, $familyName));
                }
            }
            $zip->addFromString('word/fontTable.xml', $fonts->saveXML());
            $zip->addFromString('word/_rels/fontTable.xml.rels', $relations->saveXML());
            $types = $this->read($zip, '[Content_Types].xml');
            $type = $types->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Default');
            $type->setAttribute('Extension', 'odttf');
            $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.obfuscatedFont');
            $types->documentElement->appendChild($type);
            $zip->addFromString('[Content_Types].xml', $types->saveXML());
        } finally {
            $zip->close();
        }
    }

    private function read(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = $zip->getFromName($name);
        $document = new DOMDocument;
        if (! is_string($xml) || ! $document->loadXML($xml, LIBXML_NONET)) {
            throw new ContractBuilderException('contracts.builder_export_failed', 503);
        }

        return $document;
    }

    private function compactParagraph(DOMDocument $document, DOMElement $paragraph): void
    {
        $properties = $paragraph->getElementsByTagNameNS(self::W, 'pPr')->item(0);
        if (! $properties instanceof DOMElement || $properties->parentNode !== $paragraph) {
            $properties = $document->createElementNS(self::W, 'w:pPr');
            $paragraph->insertBefore($properties, $paragraph->firstChild);
        }
        $spacing = $document->createElementNS(self::W, 'w:spacing');
        foreach (['before' => '0', 'after' => '0', 'line' => '1', 'lineRule' => 'exact'] as $key => $value) {
            $spacing->setAttributeNS(self::W, 'w:'.$key, $value);
        }
        $properties->insertBefore($spacing, $properties->firstChild);
    }
}
