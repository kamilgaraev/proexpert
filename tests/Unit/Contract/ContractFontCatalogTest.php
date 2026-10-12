<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractDocumentExporter;
use App\Services\Contract\ContractDocumentFonts;
use App\Services\Contract\ContractDocumentPrintLayout;
use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractFontCatalog;
use App\Services\Contract\ContractTypography;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractFontCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_all_twenty_one_fonts_have_four_distinct_faces_and_support_cyrillic_measurement(): void
    {
        self::assertCount(21, ContractFontCatalog::FAMILIES);
        foreach (ContractFontCatalog::FAMILIES as $family) {
            ContractTypography::validate(['fontFamily' => $family]);
            $pdf = ContractDocumentFonts::pdf([$family]);
            $metrics = $pdf->getFontMetrics();
            $paths = [];
            foreach (['normal' => 'regular', 'bold' => 'bold', 'italic' => 'italic', 'bold_italic' => 'bolditalic'] as $subtype => $variant) {
                $path = $metrics->getFont($family, $subtype);
                self::assertNotNull($path, $family.' '.$subtype);
                self::assertGreaterThan(0, $metrics->getTextWidth('Договор Ёё 123', $path, 12));
                self::assertSame($family, ContractDocumentFonts::actualFamily($metrics, $path));
                self::assertNotEmpty(ContractDocumentFonts::bytes($variant, $family));
                $paths[] = $path;
            }
            self::assertCount(4, array_unique($paths), $family);
        }
    }

    public function test_missing_currency_glyph_is_preserved_in_positioned_pdf_and_embedded_word_fonts(): void
    {
        $text = 'Договор: ₽ € 123,45';
        $document = ['type' => 'doc', 'attrs' => ['layout' => ['version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait',
            'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]], 'grid' => ['size' => 5, 'snap' => true, 'visible' => false]]],
            'content' => [['type' => 'paragraph', 'attrs' => ['layout' => ['id' => 'sample', 'x' => 0, 'y' => 0, 'width' => 170, 'minHeight' => 0, 'fontFamily' => 'Lato']],
                'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'bold'], ['type' => 'italic']]]]]]];
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $plan = (new ContractDocumentPrintLayout)->decode($html);
        $items = array_values(array_filter($plan['pages'][0], static fn (array $item): bool => $item['type'] === 'text'));
        self::assertSame($text, implode('', array_column($items, 'text')));
        self::assertContains('Lato', array_column($items, 'fontFamily'));
        self::assertContains('DejaVu Sans', array_column($items, 'fontFamily'));
        $pdf = (new ContractDocumentExporter)->render($html, 'pdf');
        self::assertStringContainsString('₽', (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText());
        $this->assertWord((new ContractDocumentExporter)->render($html, 'docx'), $text, false);
    }

    public function test_flow_word_keeps_paragraphs_and_uses_explicit_glyph_fallback(): void
    {
        $text = 'Текст ₽ € без потерь';
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['format' => ['fontFamily' => 'Lato']], 'content' => [['type' => 'text', 'text' => $text]]],
            ['type' => 'paragraph', 'attrs' => ['format' => ['fontFamily' => 'Lato']], 'content' => [['type' => 'text', 'text' => 'Второй абзац']]],
        ]];
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $this->assertWord((new ContractDocumentExporter)->render($html, 'docx'), $text, true);
    }

    private function assertWord(string $bytes, string $text, bool $flow): void
    {
        $file = tempnam(sys_get_temp_dir(), 'most-font-check-');
        file_put_contents($file, $bytes);
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            self::assertStringContainsString('w:ascii="Lato"', $xml);
            self::assertStringContainsString('w:ascii="DejaVu Sans"', $xml);
            $dom = new \DOMDocument;
            $dom->loadXML($xml);
            $query = new \DOMXPath($dom);
            $query->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $content = implode('', array_map(static fn ($node): string => $node->textContent, iterator_to_array($query->query('//w:t'))));
            self::assertStringContainsString($text, $content);
            if ($flow) self::assertGreaterThanOrEqual(2, $query->query('//w:body/w:p')->length);
            $fonts = $zip->getFromName('word/fontTable.xml');
            self::assertStringContainsString('w:name="Lato"', $fonts);
            self::assertStringContainsString('w:embedBoldItalic', $fonts);
            $zip->close();
        } finally { unlink($file); }
    }
}
