<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentExporter;
use Tests\TestCase;
use ZipArchive;

final class ContractDocumentExporterTest extends TestCase
{
    public function test_docx_keeps_cyrillic_formatting_merged_cells_and_clause_links(): void
    {
        $bytes = (new ContractDocumentExporter)->render(self::html(), 'docx');
        $path = tempnam(sys_get_temp_dir(), 'most-export-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            self::assertIsString($xml);
            self::assertTrue((new \DOMDocument)->loadXML($xml, LIBXML_NONET));
            self::assertStringContainsString('А&amp;Б', $xml);
            self::assertStringContainsString('Монтаж оборудования', $xml);
            self::assertStringContainsString('125 000,00 RUB', $xml);
            self::assertStringContainsString('w:vMerge w:val="restart"', $xml);
            self::assertStringContainsString('w:vMerge w:val="continue"', $xml);
            self::assertStringContainsString('w:gridSpan w:val="2"', $xml);
            self::assertStringContainsString('<w:b', $xml);
            self::assertStringContainsString('Heading1', $zip->getFromName('word/styles.xml'));
            self::assertStringContainsString('w:val="36"', $zip->getFromName('word/styles.xml'));
            $bookmark = 'clause_'.substr(hash('sha256', 'clause-payment'), 0, 24);
            self::assertStringContainsString('w:name="'.$bookmark.'"', $xml);
            self::assertStringContainsString('w:anchor="'.$bookmark.'"', $xml);
            self::assertStringContainsString('https://example.com/terms', $zip->getFromName('word/_rels/document.xml.rels'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_pdf_is_generated_from_the_same_frozen_document(): void
    {
        $bytes = (new ContractDocumentExporter)->render(self::html(), 'pdf');
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertGreaterThan(10000, strlen($bytes));
    }

    public function test_export_rejects_active_content_and_unbounded_input(): void
    {
        foreach (['<img src="https://example.com/file">', '<script>alert(1)</script>', '<a href="javascript:alert(1)">Ссылка</a>', '<p style="background:url(file:///etc/passwd)">Текст</p>', str_repeat('x', 2 * 1024 * 1024 + 1)] as $html) {
            try {
                (new ContractDocumentExporter)->render($html, 'pdf');
                self::fail('Export cannot accept arbitrary HTML or unbounded input');
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public static function html(): string
    {
        return '<article><h1>Договор субподряда № 19</h1><p>Заказчик: ООО «Север». Исполнитель: ООО «Монтаж».</p><p>Согласовано: ООО «А&amp;Б» &lt;отдел работ&gt;.</p>'
            .'<section id="clause-work"><p class="contract-clause-number">1.</p><p><strong>Монтаж оборудования</strong> и <em>пусконаладка</em>.</p>'
            .'<table><tr><td rowspan="2"><p>Этап 1</p></td><td colspan="2"><p><strong>Работы и стоимость</strong></p></td></tr>'
            .'<tr><td><p>Монтаж</p></td><td><p>125 000,00 RUB</p></td></tr></table>'
            .'<p>Оплата согласно пункту <a href="#clause-payment">2</a>.</p></section>'
            .'<section id="clause-payment"><p class="contract-clause-number">2.</p><p>Аванс — 30%. Срок: 01.10.2026.</p>'
            .'<ol start="3"><li><p>Подготовка площадки</p></li><li><p>Приёмка работ</p></li></ol>'
            .'<p><u>Условия согласованы</u>. <s>Предыдущий текст</s>. <a href="https://example.com/terms">Приложение</a>.</p></section></article>';
    }
}
