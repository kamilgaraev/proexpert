<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class AssistantCustomReportRenderer
{
    public const MIME_TYPES = ['pdf' => 'application/pdf', 'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public function render(array $report)
    {
        $stream = tmpfile();
        if (! is_resource($stream)) {
            throw new \RuntimeException('assistant_report_stream_failed');
        }
        try {
            match ($report['format']) {
                'xlsx' => $this->xlsx($report, $stream),
                'csv' => $this->csv($report, $stream),
                'pdf' => $this->pdf($report, $stream),
            };
            if (ftell($stream) === 0 || rewind($stream) !== true) {
                throw new \RuntimeException('assistant_report_stream_failed');
            }

            return $stream;
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private function xlsx(array $report, $stream): void
    {
        $workbook = new Spreadsheet;
        try {
            foreach ($report['sections'] as $index => $section) {
                $sheet = $index === 0 ? $workbook->getActiveSheet() : $workbook->createSheet();
                $sheet->setTitle(($index + 1).' '.mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $section['title']), 0, 27));
                $data = [[$report['title']], [trans_message('ai_custom_reports.as_of'), $report['generated_at']],
                    [trans_message('ai_custom_reports.source_count'), (string) $section['source_row_count']],
                    [$section['filter_summary'] ?? ''], $section['headers'], ...$section['rows']];
                foreach ($data as $rowIndex => $row) {
                    foreach ($row as $columnIndex => $value) {
                        $text = $this->text($value);
                        $numeric = $rowIndex >= 5 && ($section['column_types'][$columnIndex] ?? null) === 'number'
                            && preg_match('/^-?\d+(?:\.\d+)?$/D', $text) === 1
                            && strlen(ltrim(str_replace(['-', '.'], '', $text), '0')) <= 15;
                        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1),
                            $text, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
                $lastColumn = Coordinate::stringFromColumnIndex(count($section['headers']));
                $sheet->getStyle('A5:'.$lastColumn.'5')->getFont()->setBold(true);
                $sheet->freezePane('A6');
                $sheet->setAutoFilter('A5:'.$lastColumn.max(5, count($data)));
                foreach (range(1, count($section['headers'])) as $column) {
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(24);
                }
            }
            $writer = new Xlsx($workbook);
            $writer->setPreCalculateFormulas(false);
            $writer->save($stream);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function csv(array $report, $stream): void
    {
        if (count($report['sections']) !== 1) {
            throw ValidationException::withMessages(['format' => trans_message('ai_custom_reports.csv_single_section')]);
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $section = $report['sections'][0];
        foreach ([$section['headers'], ...$section['rows']] as $row) {
            $safe = array_map(function (mixed $value): string {
                $text = $this->text($value);

                return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) ? "'".$text : $text;
            }, $row);
            if (fputcsv($stream, $safe, ';', '"', '', "\r\n") === false) {
                throw new \RuntimeException('assistant_report_stream_failed');
            }
        }
    }

    private function pdf(array $report, $stream): void
    {
        if (array_sum(array_map(static fn (array $section): int => count($section['rows']), $report['sections'])) > 300) {
            throw ValidationException::withMessages(['format' => trans_message('ai_custom_reports.pdf_row_limit')]);
        }
        $pdf = Pdf::loadView('reports.custom-report-pdf', ['report' => $report])->setPaper('a4', 'landscape');
        $pdf->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $content = $pdf->output();
        if (fwrite($stream, $content) !== strlen($content)) {
            throw new \RuntimeException('assistant_report_stream_failed');
        }
    }

    private function text(mixed $value): string
    {
        return $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }
}
