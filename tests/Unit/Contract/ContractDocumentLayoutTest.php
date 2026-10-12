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

    public function test_document_locales_format_values_without_losing_decimal_precision(): void
    {
        $renderer = new ContractDocumentRenderer;
        $cases = [
            'ru-RU' => ["1\u{00A0}234\u{00A0}567,8901\u{00A0}₽", '11.10.2026', 'Нет'],
            'en-GB' => ["1,234,567.8901\u{00A0}RUB", '11/10/2026', 'No'],
            'en-US' => ["1,234,567.8901\u{00A0}RUB", '10/11/2026', 'No'],
            'de-DE' => ["1.234.567,8901\u{00A0}RUB", '11.10.2026', 'Nein'],
            'fr-FR' => ["1\u{00A0}234\u{00A0}567,8901\u{00A0}RUB", '11/10/2026', 'Non'],
        ];
        foreach ($cases as $locale => [$money, $date, $boolean]) {
            self::assertSame($money, $renderer->formatValue(['type' => 'money'], ['amount' => '1234567.8901', 'currency' => 'RUB'], [], $locale));
            self::assertSame($date, $renderer->formatValue(['type' => 'date'], '2026-10-11', [], $locale));
            self::assertSame($boolean, $renderer->formatValue(['type' => 'boolean'], false, [], $locale));
        }
        self::assertSame('123,456,789,012,345,678.123456789', $renderer->formatValue(['type' => 'number'], '123456789012345678.123456789', [], 'en-US'));
    }

    public function test_document_locale_and_unicode_survive_both_export_layouts(): void
    {
        foreach ([true, false] as $positioned) {
            $content = self::content();
            $content['document']['attrs']['locale'] = 'fr-FR';
            $content['document']['content'][0]['content'][0]['text'] = 'Réception — Prüfung — Договор';
            if (! $positioned) {
                unset($content['document']['attrs']['layout'], $content['document']['content'][0]['attrs']['layout']);
            }
            (new ContractDocumentValidator)->validate($content);
            $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
            self::assertStringContainsString('lang="fr-FR"', $html);
            $exporter = new ContractDocumentExporter;
            $pdf = (new \Smalot\PdfParser\Parser)->parseContent($exporter->render($html, 'pdf'));
            self::assertStringContainsString('Réception', $pdf->getText());
            self::assertStringContainsString('Договор', $pdf->getText());
            $file = tempnam(sys_get_temp_dir(), 'most-locale-');
            file_put_contents($file, $exporter->render($html, 'docx'));
            try {
                $zip = new \ZipArchive;
                self::assertTrue($zip->open($file));
                self::assertStringContainsString('fr-FR', $zip->getFromName('word/settings.xml'));
                self::assertStringContainsString('Réception', $zip->getFromName('word/document.xml'));
                $zip->close();
            } finally { unlink($file); }
        }
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

    public function test_page_decorations_use_the_final_archive_page_count_in_html_pdf_and_word(): void
    {
        $content = self::content();
        $content['document']['attrs']['pageDecorations'] = ['header' => ['text' => 'Договор № 42 — <проект>', 'align' => 'left'],
            'footer' => ['text' => 'Согласованные условия', 'align' => 'right'],
            'pageNumbers' => ['enabled' => true, 'position' => 'footer', 'align' => 'center', 'format' => 'current_total', 'startAt' => 3, 'hideFirst' => true]];
        $second = $content['document']['content'][0];
        $second['attrs']['layout'] = [...$second['attrs']['layout'], 'id' => 'second-page', 'y' => 260];
        $content['document']['content'][] = $second;
        (new ContractDocumentValidator)->validate($content);
        $html = (new \App\Services\Contract\ContractRevisionDocumentRenderer)->renderData(['document' => $content['document'], 'definitions' => [], 'values' => [],
            'entity_snapshots' => [], 'parties' => [], 'attachments' => [], 'revision_number' => 1, 'content_hash' => str_repeat('a', 64)]);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        self::assertGreaterThanOrEqual(3, count($plan['pages']));
        $total = count($plan['pages']) + 2;
        self::assertStringContainsString('Договор № 42 — &lt;проект&gt;', $html);
        self::assertStringNotContainsString('>3 / '.$total.'<', $html);
        self::assertStringContainsString('>4 / '.$total.'<', $html);
        self::assertStringContainsString('>'.$total.' / '.$total.'<', $html);
        $exporter = new ContractDocumentExporter;
        self::assertStringStartsWith('%PDF-', $exporter->render($html, 'pdf'));
        $file = tempnam(sys_get_temp_dir(), 'most-decorations-');
        file_put_contents($file, $exporter->render($html, 'docx'));
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            self::assertStringContainsString('Договор № 42', $xml);
            self::assertStringContainsString('4 / '.$total, $xml);
            self::assertStringContainsString($total.' / '.$total, $xml);
            self::assertStringNotContainsString('3 / '.$total, $xml);
            $zip->close();
        } finally { unlink($file); }
    }

    private function rasterSource(): string
    {
        $chunk = static fn (string $kind, string $data): string => pack('N', strlen($data)).$kind.$data.pack('N', crc32($kind.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\0\xff\0\0\xff\0\0\0\xff\0\0\xff\0\0")).$chunk('IEND', '');
        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_embedded_raster_image_is_frozen_and_exported_in_pdf_and_word_without_external_storage(): void
    {
        $image = ['type' => 'image', 'attrs' => ['src' => $this->rasterSource(), 'alt' => 'Схема <объекта>', 'width' => 80, 'height' => 60]];
        $content = ['variables' => [], 'document' => ['type' => 'doc', 'content' => [$image]]];
        (new ContractDocumentValidator)->validate($content);
        $resolved = (new ContractDocumentResolver)->resolve($content, static fn () => throw new \LogicException);
        self::assertSame($content['document'], $resolved['document']);
        $html = (new ContractDocumentRenderer)->render($resolved['document'], [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        $items = array_values(array_filter($plan['pages'][0], static fn (array $item): bool => $item['type'] === 'image'));
        self::assertCount(1, $items);
        self::assertSame($image['attrs']['src'], $items[0]['src']);
        self::assertEqualsWithDelta(80 * 72 / 25.4, $items[0]['width'], .1);
        self::assertStringContainsString('alt="Схема &lt;объекта&gt;"', $html);
        $exporter = new ContractDocumentExporter;
        $pdf = $exporter->render($html, 'pdf');
        self::assertStringContainsString('/Subtype /Image', $pdf);
        $file = tempnam(sys_get_temp_dir(), 'most-image-');
        file_put_contents($file, $exporter->render($html, 'docx'));
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($file));
            self::assertStringContainsString('Схема &lt;объекта&gt;', $zip->getFromName('word/document.xml'));
            $media = [];
            for ($index = 0; $index < $zip->numFiles; $index++) { $name = $zip->getNameIndex($index); if (str_starts_with($name, 'word/media/')) { $media[] = $zip->getFromName($name); } }
            self::assertContains(\App\Services\Contract\ContractInlineImage::bytes($image['attrs']['src']), $media);
            $zip->close();
        } finally { unlink($file); }
    }

    public function test_inline_image_fits_its_table_column_and_merged_cell_instead_of_the_whole_block(): void
    {
        $image = ['type' => 'image', 'attrs' => ['src' => $this->rasterSource(), 'alt' => 'Схема', 'width' => 80, 'height' => 60]];
        $document = ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['columnWidths' => [20, 80]], 'content' => [
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['rowspan' => 2], 'content' => [$image]], ['type' => 'tableCell', 'content' => [$image]]]],
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$image]]]],
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 2], 'content' => [$image]]]],
        ]]]];
        (new ContractDocumentValidator)->validate(['variables' => [], 'document' => $document]);
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        $images = array_values(array_filter(array_merge(...$plan['pages']), static fn (array $item): bool => $item['type'] === 'image'));
        self::assertCount(4, $images);
        self::assertLessThanOrEqual(170 * .2 * 72 / 25.4 - 10, $images[0]['width']);
        self::assertEqualsWithDelta($images[1]['width'], $images[2]['width'], .01);
        self::assertEqualsWithDelta(80 * 72 / 25.4, $images[3]['width'], .1);
        self::assertEqualsWithDelta(.75, $images[0]['height'] / $images[0]['width'], .01);
    }

    public function test_print_plan_limits_final_image_bytes_including_repeated_headers(): void
    {
        $raster = imagecreatetruecolor(256, 256);
        $pixels = random_bytes(256 * 256 * 3);
        for ($y = 0; $y < 256; $y++) { for ($x = 0; $x < 256; $x++) {
            $offset = ($y * 256 + $x) * 3;
            imagesetpixel($raster, $x, $y, (ord($pixels[$offset]) << 16) | (ord($pixels[$offset + 1]) << 8) | ord($pixels[$offset + 2]));
        } }
        ob_start(); imagepng($raster); $bytes = ob_get_clean(); imagedestroy($raster);
        self::assertGreaterThan(150000, strlen($bytes));
        $layout = self::content()['document']['attrs']['layout'];
        $unit = 72 / 25.4;
        $item = ['type' => 'image', 'x' => 20, 'y' => 20, 'width' => 80, 'height' => 60, 'alt' => 'Повторяемая шапка', 'src' => 'data:image/png;base64,'.base64_encode($bytes)];
        $plan = ['version' => 1, 'page' => $layout['page'], 'width' => 210 * $unit, 'height' => 297 * $unit, 'pages' => array_fill(0, 12, [$item])];
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.builder_image_budget'));
        (new \App\Services\Contract\ContractPrintPlanValidator)->validate($plan);
    }

    public function test_five_hundred_fields_render_over_thirty_pages_without_loss_or_duplicate_values(): void
    {
        $content = self::content(); $content['document']['content'] = []; $definitions = $values = [];
        for ($page = 0; $page < 32; $page++) {
            $children = [];
            for ($offset = 0; $offset < 16 && $page * 16 + $offset < 500; $offset++) {
                $index = $page * 16 + $offset + 1;
                $id = sprintf('00000000-0000-4000-8000-%012d', $index);
                $definitions[$id] = ['id' => $id, 'version' => 1, 'title' => 'Поле '.$index, 'definition' => ['type' => 'text', 'required' => true]];
                $values[$id] = sprintf('Value%04d', $index);
                $children[] = ['type' => 'variable', 'attrs' => ['variableId' => $id]];
                $children[] = ['type' => 'text', 'text' => ' '];
            }
            $content['document']['content'][] = ['type' => 'paragraph', 'attrs' => ['layout' => ['id' => 'page-'.$page, 'x' => 0, 'y' => $page * 257 + 1, 'width' => 170, 'minHeight' => 0]], 'content' => $children];
        }
        $html = (new ContractDocumentRenderer)->render($content['document'], $definitions, $values);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        self::assertCount(32, $plan['pages']);
        $text = implode(' ', array_column(array_merge(...$plan['pages']), 'text'));
        foreach ($values as $value) { self::assertSame(1, substr_count($text, $value), $value); }
        self::assertStringStartsWith('%PDF-', (new ContractDocumentExporter)->render($html, 'pdf'));
        self::assertStringStartsWith('PK', (new ContractDocumentExporter)->render($html, 'docx'));
    }

    public function test_images_reject_remote_paths_svg_mime_mismatch_and_broken_pixel_data(): void
    {
        $source = $this->rasterSource();
        foreach (['file:///etc/passwd', 'https://example.org/image.png', 'data:image/svg+xml;base64,'.base64_encode('<svg/>'),
            str_replace('image/png', 'image/jpeg', $source), substr($source, 0, 70), 'data:image/png;base64,'.base64_encode(str_repeat('x', 512001))] as $invalid) {
            $content = ['variables' => [], 'document' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $invalid, 'alt' => '', 'width' => 80, 'height' => 60]]]]];
            try { (new ContractDocumentValidator)->validate($content); self::fail('Unsafe image accepted'); }
            catch (ContractBuilderException $error) { self::assertSame('contracts.builder_image_invalid', $error->messageKey()); }
        }
    }

    public function test_page_decorations_reject_invalid_settings_and_insufficient_margins(): void
    {
        foreach ([['header' => ['text' => str_repeat('Текст ', 100), 'align' => 'left']], ['header' => ['text' => 'Договор', 'align' => 'absolute']],
            ['header' => ['text' => 'Договор', 'align' => 'left', 'html' => '<script>']], ['pageNumbers' => ['enabled' => true]]] as $decorations) {
            $content = self::content();
            $content['document']['attrs']['layout']['page']['margins']['top'] = 3;
            $content['document']['attrs']['pageDecorations'] = $decorations;
            try { (new ContractDocumentValidator)->validate($content); self::fail('Invalid page decoration accepted'); }
            catch (ContractBuilderException $error) { self::assertSame('contracts.builder_page_decorations_invalid', $error->messageKey()); }
        }
    }

    public function test_keep_with_next_moves_heading_with_the_first_line_of_the_following_block(): void
    {
        $content = self::content();
        $content['document']['content'][0] = ['type' => 'heading', 'attrs' => ['level' => 2, 'layout' => ['id' => 'heading', 'x' => 0, 'y' => 246,
            'width' => 170, 'minHeight' => 0, 'fontSize' => 10, 'keepWithNext' => true]], 'content' => [['type' => 'text', 'text' => 'Гарантия']]];
        $content['document']['content'][] = ['type' => 'paragraph', 'attrs' => ['layout' => ['id' => 'after-heading', 'x' => 0, 'y' => 250, 'width' => 170, 'minHeight' => 0]],
            'content' => [['type' => 'text', 'text' => 'Гарантийные обязательства']]];
        (new ContractDocumentValidator)->validate($content);
        $html = (new ContractDocumentRenderer)->render($content['document'], [], []);
        $plan = (new \App\Services\Contract\ContractDocumentPrintLayout)->decode($html);
        self::assertCount(2, $plan['pages']);
        self::assertSame([], array_filter($plan['pages'][0], static fn (array $item): bool => $item['type'] === 'text'));
        $text = implode(' ', array_column(array_filter($plan['pages'][1], static fn (array $item): bool => $item['type'] === 'text'), 'text'));
        self::assertStringContainsString('Гарантия', $text);
        self::assertStringContainsString('Гарантийные', $text);
    }

    public function test_invalid_geometry_and_duplicate_ids_are_rejected(): void
    {
        foreach (['width' => -1, 'x' => INF, 'minHeight' => -2, 'align' => 'absolute', 'lineHeight' => 100,
            'fontFamily' => 'Arial', 'fontSize' => 73, 'style' => 'url(file:///secret)'] as $field => $value) {
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
            self::assertStringContainsString('relativeFrom="page"', $xml);
            self::assertStringContainsString('w:top="1134"', $xml);
            $document = new \DOMDocument;
            $document->loadXML($xml);
            $query = new \DOMXPath($document);
            $query->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $query->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
            $query->registerNamespace('wps', 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape');
            $shapes = $query->query('//wp:anchor');
            self::assertGreaterThanOrEqual(2, $shapes->length);
            $horizontalOffsets = [];
            foreach ($shapes as $shape) {
                self::assertSame('page', $query->query('wp:positionH', $shape)->item(0)->getAttribute('relativeFrom'));
                self::assertSame('page', $query->query('wp:positionV', $shape)->item(0)->getAttribute('relativeFrom'));
                self::assertGreaterThan(0, (int) $query->query('wp:positionV/wp:posOffset', $shape)->item(0)->textContent);
                $horizontalOffsets[] = $query->query('wp:positionH/wp:posOffset', $shape)->item(0)->textContent;
                $body = $query->query('.//wps:bodyPr', $shape)->item(0);
                foreach (['lIns', 'rIns', 'tIns', 'bIns'] as $inset) self::assertSame('0', $body->getAttribute($inset));
                self::assertSame('none', $body->getAttribute('wrap'));
            }
            self::assertGreaterThanOrEqual(2, count(array_unique($horizontalOffsets)));
            $ids = array_map(static fn ($node) => $node->getAttribute('id'), iterator_to_array($query->query('//wp:docPr')));
            self::assertCount(count($ids), array_unique($ids));
            self::assertSame(1, substr_count($xml, '>Подрядчик<'));
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
