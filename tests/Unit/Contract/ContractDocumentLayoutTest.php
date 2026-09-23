<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentExporter;
use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractDocumentResolver;
use App\Services\Contract\ContractDocumentValidator;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractDocumentLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public static function content(): array
    {
        return ['variables' => [], 'document' => ['type' => 'doc', 'attrs' => ['layout' => [
            'version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait',
                'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'grid' => ['size' => 5, 'snap' => true, 'visible' => true],
        ]], 'content' => [['type' => 'paragraph', 'attrs' => ['layout' => [
            'id' => 'block-one', 'x' => 0, 'y' => 10, 'width' => 80, 'minHeight' => 25,
            'align' => 'left', 'firstLineIndent' => 5, 'lineHeight' => 1.5, 'spaceAfter' => 3,
        ]], 'content' => [['type' => 'text', 'text' => 'Условия договора']]]]]];
    }

    public function test_layout_survives_resolution_without_rewriting_content(): void
    {
        $content = self::content();
        (new ContractDocumentValidator)->validate($content);
        $resolved = (new ContractDocumentResolver)->resolve($content, static fn () => throw new \LogicException('No dependencies'));
        self::assertSame($content['document'], $resolved['document']);
    }

    public function test_legacy_document_remains_unchanged(): void
    {
        $content = ['variables' => [], 'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Прежний текст']]],
        ]]];
        $resolved = (new ContractDocumentResolver)->resolve($content, static fn () => throw new \LogicException('No dependencies'));
        self::assertSame($content['document'], $resolved['document']);
    }

    public function test_invalid_geometry_and_duplicate_ids_are_rejected(): void
    {
        foreach (['width' => -1, 'x' => INF, 'minHeight' => -2, 'align' => 'absolute', 'lineHeight' => 100,
            'fontFamily' => 'Arial', 'fontSize' => 21, 'style' => 'url(file:///secret)'] as $field => $value) {
            $content = self::content();
            $content['document']['content'][0]['attrs']['layout'][$field] = $value;
            try {
                (new ContractDocumentValidator)->validate($content);
                self::fail('Invalid layout must fail: '.$field);
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        $content = self::content();
        $content['document']['content'][] = $content['document']['content'][0];
        $this->expectException(ContractBuilderException::class);
        (new ContractDocumentValidator)->validate($content);
    }

    public function test_referenced_section_keeps_placement_and_namespaces_its_children(): void
    {
        $content = self::content();
        $layout = $content['document']['content'][0]['attrs']['layout'];
        $section = self::content();
        $content['document']['content'] = [['type' => 'blockReference', 'attrs' => [
            'blockId' => '10000000-0000-4000-8000-000000000001', 'version' => 1, 'instanceId' => 'copyone', 'layout' => $layout,
        ]]];
        $resolved = (new ContractDocumentResolver)->resolve($content, static fn () => ['id' => 1, 'title' => 'Раздел', 'content' => $section]);
        self::assertSame($layout, $resolved['document']['content'][0]['attrs']['layout']);
        self::assertSame('group', $resolved['document']['content'][0]['type']);
        self::assertSame('copyone__block-one', $resolved['document']['content'][0]['content'][0]['attrs']['layout']['id']);
    }

    public function test_print_keeps_side_by_side_geometry_and_page_settings_in_pdf_and_docx(): void
    {
        $content = self::content();
        $second = $content['document']['content'][0];
        $second['attrs']['layout'] = [...$second['attrs']['layout'], 'id' => 'block-two', 'x' => 90];
        $second['content'][0]['text'] = 'Подрядчик';
        $content['document']['content'][] = $second;
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        self::assertStringContainsString('data-contract-print=', $html);
        self::assertStringContainsString('Подрядчик', $html);
        $exporter = new ContractDocumentExporter;
        $pdf = $exporter->render($html, 'pdf');
        self::assertStringStartsWith('%PDF-', $pdf);
        $file = tempnam(sys_get_temp_dir(), 'most-layout-docx-');
        file_put_contents($file, $exporter->render($html, 'docx'));
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            self::assertStringContainsString('Подрядчик', $xml);
            self::assertStringContainsString('mso-position-horizontal-relative:page', $xml);
            self::assertStringContainsString('w:top="1134"', $xml);
            $document = new \DOMDocument;
            $document->loadXML($xml);
            $query = new \DOMXPath($document);
            $query->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $query->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
            $shapes = $query->query('//v:shape');
            self::assertGreaterThanOrEqual(2, $shapes->length);
            $horizontalOffsets = [];
            foreach ($shapes as $shape) {
                $style = $shape->getAttribute('style');
                self::assertStringContainsString('mso-position-horizontal:absolute', $style);
                self::assertStringContainsString('mso-position-vertical:absolute', $style);
                self::assertMatchesRegularExpression('/margin-top:[1-9][0-9.]*pt/', $style);
                preg_match('/margin-left:([0-9.]+)pt/', $style, $offset);
                $horizontalOffsets[] = $offset[1];
            }
            self::assertGreaterThanOrEqual(2, count(array_unique($horizontalOffsets)));
            self::assertSame(1, $query->query('/w:document/w:body/w:p')->length);
            self::assertStringContainsString('w:embedRegular', $zip->getFromName('word/fontTable.xml'));
            self::assertNotFalse($zip->getFromName('word/fonts/regular.odttf'));
            $zip->close();
        } finally {
            unlink($file);
        }
    }

    public function test_print_splits_long_text_without_losing_words_or_overlapping_following_blocks(): void
    {
        $content = self::content();
        $content['document']['content'][0]['attrs']['layout']['fontFamily'] = 'DejaVu Serif';
        $content['document']['content'][0]['attrs']['layout']['fontSize'] = 16;
        $content['document']['content'][0]['content'][0]['text'] = str_repeat('Длинное условие договора. ', 200).'КОНЕЦ';
        $following = $content['document']['content'][0];
        $following['attrs']['layout'] = [...$following['attrs']['layout'], 'id' => 'after-long-text', 'y' => 15, 'fontSize' => 10];
        $following['content'][0]['text'] = 'Следующий блок';
        $content['document']['content'][] = $following;
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        self::assertStringContainsString('data-contract-print=', $html);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        self::assertGreaterThan(1, count($plan['pages']));
        $pageWithFollowingBlock = null;
        foreach ($plan['pages'] as $page => $items) {
            foreach ($items as $item) {
                if ($item['type'] === 'text' && $item['text'] === 'Следующий блок') {
                    $pageWithFollowingBlock = $page;
                }
                if ($item['type'] === 'text' && $item['text'] === 'Длинное') {
                    self::assertSame(16.0, (float) $item['fontSize']);
                }
            }
        }
        self::assertSame(count($plan['pages']) - 1, $pageWithFollowingBlock);
        $pdf = (new ContractDocumentExporter)->render($html, 'pdf');
        $parsed = (new \Smalot\PdfParser\Parser)->parseContent($pdf);
        self::assertSame(count($plan['pages']), count($parsed->getPages()));
        self::assertStringContainsString('КОНЕЦ', $parsed->getText());
        self::assertStringContainsString('Следующий блок', $parsed->getText());
        self::assertSame(200, substr_count($parsed->getText(), 'Длинное'));
    }

    public function test_clause_text_placement_and_free_font_settings_reach_positioned_exports(): void
    {
        $content = self::content();
        $layout = $content['document']['content'][0]['attrs']['layout'];
        $layout['fontFamily'] = 'DejaVu Serif';
        $layout['fontSize'] = 16;
        $content['document']['content'] = [[
            'type' => 'clause',
            'attrs' => ['id' => 'formatted', 'textPlacement' => 'new_line', 'layout' => $layout],
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Условия',
                    'marks' => [['type' => 'bold'], ['type' => 'underline']],
                ]],
            ]],
        ]];

        $resolved = (new ContractDocumentResolver)->resolve($content, static fn () => throw new \LogicException('No dependencies'));
        self::assertSame('new_line', $resolved['document']['content'][0]['attrs']['textPlacement']);
        $html = (new ContractDocumentRenderer)->render($resolved['document'], [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        $texts = array_values(array_filter($plan['pages'][0], static fn (array $item): bool => $item['type'] === 'text'));
        self::assertSame('DejaVu Serif', $texts[0]['fontFamily']);
        self::assertEqualsWithDelta(16, $texts[0]['fontSize'], .01);
        self::assertNotSame($texts[0]['y'], $texts[1]['y']);
        self::assertTrue($texts[1]['bold']);
        self::assertTrue($texts[1]['underline']);

        $file = tempnam(sys_get_temp_dir(), 'most-layout-serif-');
        file_put_contents($file, (new ContractDocumentExporter)->render($html, 'docx'));
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            self::assertStringContainsString('w:ascii="DejaVu Serif"', $xml);
            self::assertStringContainsString('w:sz w:val="32"', $xml);
            self::assertNotFalse($zip->getFromName('word/fonts/serif-regular.odttf'));
            $zip->close();
        } finally {
            unlink($file);
        }
    }

    public function test_positioned_export_rejects_modified_html_and_active_links(): void
    {
        $content = self::content();
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        $layout = new \App\Services\Contract\ContractDocumentPrintLayout;
        $plan = $layout->decode($html);
        foreach ([$html.'<script>alert(1)</script>', str_replace('background:white', 'background:url(file:///secret)', $html)] as $modified) {
            try {
                (new ContractDocumentExporter)->render($modified, 'pdf');
                self::fail('Tampered HTML must fail');
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        $plan['pages'][0][0]['href'] = 'javascript:alert(1)';
        $this->expectException(ContractBuilderException::class);
        (new ContractDocumentExporter)->render($layout->html($plan), 'pdf');
    }

    public function test_group_keeps_relative_columns_and_outer_placement(): void
    {
        $content = self::content();
        $left = $content['document']['content'][0];
        $left['attrs']['layout']['x'] = 0;
        $left['attrs']['layout']['y'] = 0;
        $right = $left;
        $right['attrs']['layout']['id'] = 'right';
        $right['attrs']['layout']['x'] = 90;
        $right['content'][0]['text'] = 'Подрядчик';
        $content['document']['content'] = [['type' => 'group', 'attrs' => ['layout' => [
            'id' => 'section', 'x' => 0, 'y' => 40, 'width' => 170, 'minHeight' => 0,
            'fontFamily' => 'DejaVu Sans Mono', 'fontSize' => 14,
        ]], 'content' => [$left, $right]]];
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        $texts = array_values(array_filter($plan['pages'][0], static fn ($item): bool => $item['type'] === 'text'));
        self::assertSame('DejaVu Sans Mono', $texts[0]['fontFamily']);
        self::assertEqualsWithDelta(14, $texts[0]['fontSize'], .01);
        self::assertSame('DejaVu Sans Mono', $texts[1]['fontFamily']);
        self::assertEqualsWithDelta(14, $texts[1]['fontSize'], .01);
        self::assertEqualsWithDelta($texts[0]['y'], $texts[1]['y'], .01);
        self::assertEqualsWithDelta(90 * 72 / 25.4, $texts[1]['x'] - $texts[0]['x'], .01);
        self::assertGreaterThanOrEqual(60 * 72 / 25.4, $texts[0]['y']);
    }

    public function test_print_preserves_long_table_cells_marks_and_clause_links(): void
    {
        $content = self::content();
        $layout = $content['document']['content'][0]['attrs']['layout'];
        $content['document']['content'] = [
            ['type' => 'clause', 'attrs' => ['id' => 'payment', 'layout' => [...$layout, 'id' => 'clause']], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Оплата', 'marks' => [['type' => 'bold'], ['type' => 'italic']]]]],
            ]],
            ['type' => 'table', 'attrs' => ['layout' => [...$layout, 'id' => 'table', 'y' => 45, 'width' => 170]], 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('Обязательство ', 350)]]]]],
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'clauseReference', 'attrs' => ['target' => 'payment']]]]]],
                ]],
            ]],
        ];
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        self::assertGreaterThan(1, count($plan['pages']));
        $items = array_merge(...$plan['pages']);
        self::assertNotEmpty(array_filter($items, static fn ($item): bool => $item['type'] === 'anchor' && $item['name'] === 'clause-payment'));
        self::assertNotEmpty(array_filter($items, static fn ($item): bool => $item['type'] === 'text' && $item['bold'] && $item['italic']));
        self::assertNotEmpty(array_filter($items, static fn ($item): bool => $item['type'] === 'text' && $item['href'] === '#clause-payment'));
        $pdf = (new ContractDocumentExporter)->render($html, 'pdf');
        $parsed = (new \Smalot\PdfParser\Parser)->parseContent($pdf);
        self::assertSame(350, substr_count($parsed->getText(), 'Обязательство'));
    }
}
