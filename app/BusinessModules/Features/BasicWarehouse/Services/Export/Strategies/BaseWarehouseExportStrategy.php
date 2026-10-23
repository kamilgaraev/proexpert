<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts\WarehouseExportStrategyInterface;
use App\Models\Organization;
use App\Services\Storage\FileService;
use DateTimeInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Базовая стратегия экспорта
 */
abstract class BaseWarehouseExportStrategy implements WarehouseExportStrategyInterface
{
    public function __construct(protected FileService $fileService) {}

    /**
     * Сохранение Spreadsheet в S3
     */
    protected function saveSpreadsheetToS3(Spreadsheet $spreadsheet, string $path, Organization|int|string $organization): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $organizationModel = $organization instanceof Organization ? $organization : null;
        $orgId = $organizationModel?->id ?? $organization;
        $s3Path = "org-{$orgId}/{$path}";

        $this->fileService->disk($organizationModel)->put($s3Path, $content);

        return $s3Path;
    }

    protected function documentNumber(mixed $number): string
    {
        $trimmed = trim((string) $number);
        $normalized = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return $normalized !== '' ? $normalized : trans_message('warehouse_basic.document_without_number');
    }

    protected function documentFileSuffix(mixed $number, DateTimeInterface $date): string
    {
        $businessNumber = Str::slug(trim((string) $number));

        return $businessNumber !== '' ? $businessNumber : $date->format('Ymd_His');
    }

    protected function filenameFragment(mixed $value, string $fallback): string
    {
        $fragment = Str::slug(trim((string) $value));

        return $fragment !== '' ? $fragment : $fallback;
    }

    protected function applyReceiptPrintLayout(Worksheet $sheet, int $headerRow): void
    {
        $lastRow = $sheet->getHighestRow();
        $lastDataRow = $lastRow - 3;
        $sheet->setShowGridlines(false);
        $sheet->getStyle("A1:I{$lastRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle("A1:I{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (['A' => 18, 'B' => 12, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 14, 'G' => 14, 'H' => 20, 'I' => 23] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach (range(1, $lastRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(max(18, $sheet->getRowDimension($row)->getRowHeight()));
        }
        $sheet->getStyle('G1:I3')->getFont()->setSize(8);
        $sheet->getStyle('G1:I3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A6')->getFont()->setSize(8);
        $sheet->getStyle('A9')->getFont()->setSize(12);
        $sheet->getStyle('H10:I10')->getFont()->setSize(8);
        $sheet->mergeCells('A6:G6');
        foreach ([5, 9, 13, 14, 15] as $row) {
            if ($sheet->getCell("A{$row}")->getValue() === null) {
                continue;
            }
            $range = $row === 5 ? 'A5:G5' : "A{$row}:I{$row}";
            if (!isset($sheet->getMergeCells()[$range])) {
                $sheet->mergeCells($range);
            }
            $this->fitWrappedCell($sheet, $range);
        }
        foreach (range(1, 3) as $row) {
            $this->fitWrappedCell($sheet, "G{$row}:I{$row}");
        }
        foreach (['H6', 'H7', 'I7', 'H10', 'I10', 'H11', 'I11'] as $cell) {
            $this->fitWrappedCell($sheet, $cell);
        }
        foreach (range($headerRow, $lastDataRow) as $row) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $this->fitWrappedCell($sheet, "A{$row}:D{$row}");
            $this->fitWrappedCell($sheet, "E{$row}");
            if ($row === $headerRow) {
                foreach (["F{$row}", "G{$row}", "H{$row}", "I{$row}"] as $range) {
                    $this->fitWrappedCell($sheet, $range);
                }
            } else {
                $sheet->getStyle("F{$row}:I{$row}")->getAlignment()->setWrapText(false)->setShrinkToFit(true)
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        foreach (range($lastDataRow + 2, $lastRow) as $row) {
            $range = "A{$row}:I{$row}";
            if (!isset($sheet->getMergeCells()[$range])) {
                $sheet->mergeCells($range);
            }
            $this->fitWrappedCell($sheet, $range, 24);
        }
        foreach ([4, 8, 12, $headerRow - 1, $lastDataRow + 1] as $row) {
            if ($sheet->getCell("A{$row}")->getValue() === null) {
                $sheet->getRowDimension($row)->setRowHeight(7);
            }
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)
            ->setPrintArea("A1:I{$lastRow}")->setRowsToRepeatAtTop([$headerRow, $headerRow]);
        $sheet->getPageMargins()->setTop(0.25)->setBottom(0.3)->setLeft(0.2)->setRight(0.2)->setHeader(0.1)->setFooter(0.15);
        $number = str_replace('&', '&&', (string) $sheet->getCell('H11')->getValue());
        $sheet->getHeaderFooter()->setOddFooter('&L&8М-7 № '.$number.'&R&8Страница &P из &N');
    }

    protected function fitWrappedCell(Worksheet $sheet, string $range, float $minimumHeight = 18): void
    {
        [$start, $end] = Coordinate::rangeBoundaries($range);
        $row = (int) $start[1];
        $anchor = Coordinate::stringFromColumnIndex($start[0]).$row;
        $width = 0.0;
        foreach (range($start[0], $end[0]) as $column) {
            $columnWidth = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->getWidth();
            $width += $columnWidth > 0 ? $columnWidth : 8.43;
        }
        $fontSize = $sheet->getStyle($anchor)->getFont()->getSize() ?? 10;
        $capacity = max(1, (int) floor(($width - 1) * 11 / max($fontSize, 1) * 0.8));
        $lines = 0;
        foreach (explode("\n", str_replace("\r", '', (string) $sheet->getCell($anchor)->getValue())) as $line) {
            $paragraphLines = 1;
            $usedWidth = 0;
            foreach (preg_split('/(?<=[\s\-\/])/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $wordWidth = mb_strwidth($word, 'UTF-8');
                if ($usedWidth > 0 && $usedWidth + $wordWidth > $capacity) {
                    $paragraphLines++;
                    $usedWidth = 0;
                }
                while ($wordWidth > $capacity) {
                    $paragraphLines++;
                    $wordWidth -= $capacity;
                }
                $usedWidth += $wordWidth;
            }
            $lines += $paragraphLines + ($paragraphLines > 1 ? 1 : 0);
        }
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setShrinkToFit(false);
        $sheet->getRowDimension($row)->setRowHeight(max(
            $sheet->getRowDimension($row)->getRowHeight(),
            $minimumHeight,
            $lines * $fontSize * 1.25 + 4,
        ));
    }

    /**
     * Установка общих стилей для таблицы
     */
    protected function applyTableStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Установка жирного шрифта
     */
    protected function setBold(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
    }

    /**
     * Центрирование текста
     */
    protected function setCenter(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Установка границ снизу (для подписей)
     */
    protected function setUnderline(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    }
}
