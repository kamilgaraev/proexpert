<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentExporter;
use App\Services\Contract\ContractDocumentPrintLayout;
use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractDocumentValidator;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractTableEditingTest extends TestCase
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
    }

    private function cell(string $text, array $attrs = []): array
    {
        return ['type' => 'tableCell', 'attrs' => $attrs, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function test_grid_rejects_overlapping_spans_and_invalid_widths(): void
    {
        $tables = [
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$this->cell('A'), $this->cell('B', ['rowspan' => 2])]], ['type' => 'tableRow', 'content' => [$this->cell('C', ['colspan' => 2])]]]],
            ['type' => 'table', 'attrs' => ['columnWidths' => [90, 90]], 'content' => [['type' => 'tableRow', 'content' => [$this->cell('A'), $this->cell('B')]]]],
            ['type' => 'table', 'attrs' => ['columnWidths' => ['50', '50']], 'content' => [['type' => 'tableRow', 'content' => [$this->cell('A'), $this->cell('B')]]]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'attrs' => ['header' => true], 'content' => [$this->cell('A', ['rowspan' => 2])]], ['type' => 'tableRow', 'content' => []]]],
        ];
        foreach ($tables as $table) {
            try { (new ContractDocumentValidator)->validate(['document' => ['type' => 'doc', 'content' => [$table]], 'variables' => []]); self::fail('Invalid table accepted'); }
            catch (ContractBuilderException $error) { self::assertSame(422, $error->getCode()); self::assertStringContainsString('Таблица', $error->getMessage()); }
        }
    }

    public function test_repeated_table_header_is_preserved_on_every_positioned_page(): void
    {
        $rows = [['type' => 'tableRow', 'attrs' => ['header' => true], 'content' => [$this->cell('Наименование'), $this->cell('Сумма')]]];
        for ($index = 1; $index <= 100; $index++) { $rows[] = ['type' => 'tableRow', 'content' => [$this->cell('Позиция '.$index), $this->cell((string) $index)]]; }
        $document = ['type' => 'doc', 'attrs' => ['layout' => ['version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]], 'grid' => ['size' => 5, 'snap' => true, 'visible' => false]]],
            'content' => [['type' => 'table', 'attrs' => ['columnWidths' => [75, 25], 'layout' => ['id' => 'table', 'x' => 0, 'y' => 0, 'width' => 170, 'minHeight' => 20]], 'content' => $rows]]];
        (new ContractDocumentValidator)->validate(['document' => $document, 'variables' => []]);
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $plan = (new ContractDocumentPrintLayout)->decode($html);
        self::assertGreaterThan(3, count($plan['pages']));
        foreach ($plan['pages'] as $page) {
            $text = implode(' ', array_column($page, 'text'));
            self::assertStringContainsString('Наименование', $text);
            self::assertStringContainsString('Сумма', $text);
        }
        $allText = implode(' ', array_map(static fn (array $page): string => implode(' ', array_column($page, 'text')), $plan['pages']));
        self::assertSame(100, substr_count($allText, 'Позиция '));
        self::assertStringStartsWith('%PDF-', (new ContractDocumentExporter)->render($html, 'pdf'));
    }

    public function test_multiline_table_rows_stay_on_one_page_when_the_row_fits(): void
    {
        $rows = [['type' => 'tableRow', 'attrs' => ['header' => true], 'content' => [$this->cell('Наименование')]]];
        for ($index = 1; $index <= 30; $index++) {
            $cell = $this->cell('Row'.$index.'Start');
            $cell['content'][] = $this->cell(str_repeat('Полные условия поставки и приёмки товара. ', 10))['content'][0];
            $cell['content'][] = $this->cell('Row'.$index.'End')['content'][0];
            $rows[] = ['type' => 'tableRow', 'content' => [$cell]];
        }
        $document = ['type' => 'doc', 'attrs' => ['layout' => ['version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]], 'grid' => ['size' => 5, 'snap' => true, 'visible' => false]]],
            'content' => [['type' => 'table', 'attrs' => ['layout' => ['id' => 'table', 'x' => 0, 'y' => 0, 'width' => 170, 'minHeight' => 0]], 'content' => $rows]]];
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $plan = (new ContractDocumentPrintLayout)->decode($html);
        self::assertGreaterThan(3, count($plan['pages']));
        foreach ($plan['pages'] as $page) {
            $text = implode(' ', array_column($page, 'text'));
            self::assertStringContainsString('Наименование', $text);
            for ($index = 1; $index <= 30; $index++) {
                self::assertSame(str_contains($text, 'Row'.$index.'Start'), str_contains($text, 'Row'.$index.'End'), 'Row '.$index);
            }
        }
        $all = implode(' ', array_column(array_merge(...$plan['pages']), 'text'));
        for ($index = 1; $index <= 30; $index++) self::assertSame(1, substr_count($all, 'Row'.$index.'Start'));
    }

    public function test_flow_export_supports_safe_typography_and_docx_table_headers(): void
    {
        $styled = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Текст договора', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontFamily' => 'DejaVu Serif', 'fontSize' => 16, 'color' => '#ab1234']]]]]];
        $document = ['type' => 'doc', 'content' => [$styled, ['type' => 'table', 'attrs' => ['columnWidths' => [75, 25]], 'content' => [
            ['type' => 'tableRow', 'attrs' => ['header' => true], 'content' => [$this->cell('Название'), $this->cell('Цена')]],
            ['type' => 'tableRow', 'content' => [$this->cell('Работа'), $this->cell('100')]],
        ]]]];
        (new ContractDocumentValidator)->validate(['document' => $document, 'variables' => []]);
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $exporter = new ContractDocumentExporter;
        self::assertStringStartsWith('%PDF-', $exporter->render($html, 'pdf'));
        $path = tempnam(sys_get_temp_dir(), 'most-table-test-');
        try {
            file_put_contents($path, $exporter->render($html, 'docx'));
            $zip = new \ZipArchive; self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml'); $zip->close();
            self::assertStringContainsString('w:tblHeader', $xml);
            self::assertStringContainsString('w:val="AB1234"', strtoupper($xml) === $xml ? $xml : str_replace('ab1234', 'AB1234', $xml));
            self::assertStringContainsString('w:val="32"', $xml);
            self::assertStringContainsString('w:w="7229"', $xml);
        } finally { if (is_file($path)) { unlink($path); } }
    }

    public function test_export_rejects_external_css_resources(): void
    {
        $this->expectException(ContractBuilderException::class);
        (new ContractDocumentExporter)->render('<article><p style="background:url(https://example.org/secret)">Текст</p></article>', 'pdf');
    }
}
