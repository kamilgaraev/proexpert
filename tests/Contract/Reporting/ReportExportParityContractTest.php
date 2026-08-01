<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

require_once dirname(__DIR__, 2).'/Unit/Reporting/Exports/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use Tests\Unit\Reporting\Exports\FakeReportPdfDocumentRenderer;
use Tests\Unit\Reporting\Exports\InMemoryReportArtifactStream;
use Tests\Unit\Reporting\Exports\ReportExportRendererTestCase;
use ZipArchive;

final class ReportExportParityContractTest extends ReportExportRendererTestCase
{
    public function test_csv_xlsx_and_pdf_preserve_online_rows_totals_columns_and_identity(): void
    {
        [$source, $definition] = $this->source(2, ['amount' => '13.75']);
        $values = [
            ['name' => 'Кран', 'amount' => '12.50', 'date' => '2026-07-29'],
            ['name' => 'Бетон', 'amount' => '1.25', 'date' => '2026-07-30'],
        ];
        $columns = ['amount', 'name'];
        $chunk = $this->chunk($source, $values);

        $csvStream = new InMemoryReportArtifactStream();
        $csvCount = (new CsvReportExportRenderer())
            ->forDefinition($definition)
            ->render($source, $this->data('csv', $columns), [$chunk], $csvStream);

        $xlsxStream = new InMemoryReportArtifactStream();
        $xlsxCount = (new XlsxReportExportRenderer())
            ->forDefinition($definition)
            ->render($source, $this->data('xlsx', $columns), [$chunk], $xlsxStream);

        $pdfDocumentRenderer = new FakeReportPdfDocumentRenderer('%PDF parity artifact');
        $budget = new ReportPdfRenderBudget(2, 2, 100_000, 100_000, 16 * 1024 * 1024);
        $pdfStream = new InMemoryReportArtifactStream();
        $pdfCount = (new PdfReportExportRenderer(
            new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
            $pdfDocumentRenderer,
            [PdfReportExportRenderer::budgetKey(
                $definition->definitionHash->value,
                $definition->definition->rendererVersion,
            ) => $budget],
        ))->forDefinition($definition)->render(
            $source,
            $this->data('pdf', $columns),
            [$chunk],
            $pdfStream,
        );

        self::assertSame([2, 2, 2], [$csvCount, $xlsxCount, $pdfCount]);
        self::assertSame(
            [['12.50', 'Кран'], ['1.25', 'Бетон']],
            $pdfDocumentRenderer->document?->rows,
        );
        self::assertSame(['amount' => '13.75'], $pdfDocumentRenderer->document?->totals);
        self::assertSame($source->run->queryHash->value, $pdfDocumentRenderer->document?->metadata['query_hash']);
        self::assertSame($source->snapshot->id, $pdfDocumentRenderer->document?->metadata['snapshot']['id']);

        $csvLines = preg_split('/\r\n/', substr($csvStream->bytes(), 3), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($csvLines);
        self::assertSame(['Сумма', 'Название'], str_getcsv($csvLines[0], ';', '"', ''));
        self::assertSame(['12,50', 'Кран'], str_getcsv($csvLines[1], ';', '"', ''));
        self::assertSame(['1,25', 'Бетон'], str_getcsv($csvLines[2], ';', '"', ''));
        self::assertSame(['13,75', 'Итого'], str_getcsv($csvLines[3], ';', '"', ''));

        $sheet = $this->sheetXml($xlsxStream->bytes());
        self::assertStringContainsString('<v>12.50</v>', $sheet);
        self::assertStringContainsString('<v>1.25</v>', $sheet);
        self::assertStringContainsString('<v>13.75</v>', $sheet);
        self::assertStringContainsString('<t>Кран</t>', $sheet);
        self::assertStringContainsString('<t>Бетон</t>', $sheet);
        self::assertSame(hash('sha256', '%PDF parity artifact'), hash('sha256', $pdfStream->bytes()));
    }

    private function sheetXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'most-parity-xlsx-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);

        try {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertIsString($xml);

            return $xml;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}
