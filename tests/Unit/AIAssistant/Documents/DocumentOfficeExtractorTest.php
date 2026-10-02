<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\DocumentTextExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

final class DocumentOfficeExtractorTest extends TestCase
{
    public function test_spreadsheet_preserves_rows_cells_and_formula_source(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Материалы')->setCellValue('A1', 'Бетон')->setCellValue('B1', 25)->setCellValue('A2', '=2+2');
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');
        try {
            (new Xlsx($book))->save($path);
            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'materials.xlsx');
            self::assertSame('ready', $result['status']);
            self::assertSame('A: Бетон | B: 25', $result['units'][0]['text']);
            self::assertSame(['sheet' => 'Материалы', 'row' => 1, 'cells' => ['A', 'B']], $result['units'][0]['provenance']);
            self::assertSame('A: =2+2', $result['units'][1]['text']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_docx_extracts_nested_text_runs_and_table_cells(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTextRun()->addText('Условия договора');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Стоимость');
        $table->addCell()->addText('12500');
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'contract.docx');
            self::assertSame('ready', $result['status']);
            self::assertStringContainsString('Условия договора', $result['text']);
            self::assertStringContainsString('Стоимость', $result['text']);
            self::assertStringContainsString('12500', $result['text']);
        } finally {
            unlink($path);
        }
    }

    public function test_docx_ooxml_extraction_includes_word_parts_without_duplicates(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addHeader()->addText('Заголовок договора');
        $section->addFooter()->addText('Подвал договора');
        $section->addTextRun()->addText('Основное условие');
        $table = $section->addTable();
        $table->addRow();
        $cell = $table->addCell();
        $cell->addText('Раздел таблицы');
        $nested = $cell->addTable();
        $nested->addRow();
        $nested->addCell()->addText('Вложенное условие');
        $table->addCell()->addText('12500');
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $archive = new \ZipArchive;
            self::assertTrue($archive->open($path));
            self::assertTrue($archive->addFromString('word/footnotes.xml', '<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:footnote w:id="1"><w:p><w:r><w:t>Текст сноски</w:t></w:r></w:p></w:footnote></w:footnotes>'));
            self::assertTrue($archive->addFromString('word/endnotes.xml', '<w:endnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:endnote w:id="1"><w:p><w:r><w:t>Текст концевой сноски</w:t></w:r></w:p></w:endnote></w:endnotes>'));
            self::assertTrue($archive->addFromString('word/comments.xml', '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:comment w:id="1"><w:p><w:r><w:t>Комментарий</w:t></w:r></w:p></w:comment></w:comments>'));
            self::assertTrue($archive->close());
            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'contract.docx');

            self::assertSame('ready', $result['status']);
            self::assertSame('full', $result['coverage']);
            self::assertStringContainsString('Заголовок договора', $result['text']);
            self::assertStringContainsString('Подвал договора', $result['text']);
            self::assertStringContainsString('Основное условие', $result['text']);
            self::assertStringContainsString('Раздел таблицы', $result['text']);
            self::assertStringContainsString('Вложенное условие', $result['text']);
            self::assertStringContainsString('12500', $result['text']);
            self::assertStringContainsString('Текст сноски', $result['text']);
            self::assertStringContainsString('Текст концевой сноски', $result['text']);
            self::assertStringContainsString('Комментарий', $result['text']);
            self::assertSame(1, substr_count($result['text'], 'Основное условие'));
        } finally {
            unlink($path);
        }
    }

    public function test_docx_archive_entry_limit_is_enforced(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');
        $archive = new \ZipArchive;

        try {
            self::assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            for ($index = 0; $index <= 10_000; $index++) {
                $archive->addFromString('entry-'.$index.'.txt', '');
            }
            self::assertTrue($archive->close());

            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'too-many.docx');

            self::assertSame('damaged', $result['status']);
            self::assertSame('unavailable', $result['coverage']);
            self::assertSame([], $result['units']);
        } finally {
            unlink($path);
        }
    }

    public function test_docx_with_dtd_or_entity_declarations_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');
        $archive = new \ZipArchive;

        try {
            self::assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            self::assertTrue($archive->addFromString('word/document.xml', '<!DOCTYPE w:document [<!ENTITY secret SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&secret;</w:t></w:r></w:p></w:body></w:document>'));
            self::assertTrue($archive->close());

            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'unsafe.docx');

            self::assertSame('damaged', $result['status']);
            self::assertSame('unavailable', $result['coverage']);
            self::assertSame('', $result['text']);
            self::assertSame([], $result['units']);
        } finally {
            unlink($path);
        }
    }

    public function test_docx_with_only_embedded_image_requires_owner_approved_ocr(): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        self::assertIsString($image);
        $imagePath = tempnam(sys_get_temp_dir(), 'most-fixture-image-');
        $path = tempnam(sys_get_temp_dir(), 'most-fixture-');
        file_put_contents($imagePath, $image);
        $word = new PhpWord;
        $word->addSection()->addImage($imagePath);

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $result = (new DocumentTextExtractor)->extract(file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'scan.docx');

            self::assertSame('ocr_quote_required', $result['status']);
            self::assertSame('image_ocr_required', $result['coverage']);
            self::assertSame(1, $result['page_count']);
            self::assertSame('', $result['text']);
            self::assertSame([], $result['units']);
        } finally {
            unlink($imagePath);
            unlink($path);
        }
    }

    public function test_damaged_office_archives_leave_no_temporary_document(): void
    {
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-doc-*');
        foreach (['xlsx', 'xls', 'docx'] as $extension) {
            $result = (new DocumentTextExtractor)->extract('damaged content', 'application/octet-stream', 'damaged.'.$extension);
            self::assertSame('damaged', $result['status']);
        }
        self::assertSame($before, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-doc-*'));
    }
}
