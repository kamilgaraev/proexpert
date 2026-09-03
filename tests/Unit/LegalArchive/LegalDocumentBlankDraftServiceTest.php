<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Editor\LegalDocumentBlankDraftService;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LegalDocumentBlankDraftServiceTest extends TestCase
{
    public function test_it_creates_a_valid_neutral_docx_package(): void
    {
        $service = (new \ReflectionClass(LegalDocumentBlankDraftService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'createDocx');
        $method->setAccessible(true);
        $title = 'Черновик договора «МОСТ» & <условия>';
        $path = $method->invoke($service, $title);
        self::assertIsString($path);

        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path) === true);
            self::assertNotFalse($zip->locateName('[Content_Types].xml'));
            self::assertNotFalse($zip->locateName('word/document.xml'));
            self::assertStringContainsString('Черновик договора', (string) $zip->getFromName('word/document.xml'));
            $document = new DOMDocument;
            self::assertTrue($document->loadXML((string) $zip->getFromName('word/document.xml')));
            self::assertSame($title, $document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't')->item(0)?->textContent);
            $styles = $zip->getFromName('word/styles.xml');
            self::assertIsString($styles);
            $styleDocument = new DOMDocument;
            self::assertTrue($styleDocument->loadXML($styles));
            $xpath = new DOMXPath($styleDocument);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            self::assertSame('ru-RU', $xpath->evaluate('string(/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:lang/@w:val)'));
            self::assertStringContainsString('PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"', (string) $zip->getFromName('[Content_Types].xml'));
            $relationships = new DOMDocument;
            self::assertTrue($relationships->loadXML((string) $zip->getFromName('word/_rels/document.xml.rels')));
            $relationshipXpath = new DOMXPath($relationships);
            $relationshipXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            self::assertSame('styles.xml', $relationshipXpath->evaluate('string(/r:Relationships/r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"]/@Target)'));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
