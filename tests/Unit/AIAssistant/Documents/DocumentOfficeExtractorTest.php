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
