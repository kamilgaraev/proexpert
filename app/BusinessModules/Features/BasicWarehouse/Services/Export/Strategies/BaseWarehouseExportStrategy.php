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
        $directory = dirname($path);
        $filename = basename($path);
        $preparationId = Str::uuid();
        $s3Path = "org-{$orgId}/{$directory}/{$preparationId}/{$filename}";

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
